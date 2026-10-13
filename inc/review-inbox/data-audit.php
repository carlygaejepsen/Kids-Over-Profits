<?php
/**
 * Review inbox source: corrections from the data audit (inc/data-audit.php,
 * docs/PLAN.md 3.13). One card per thing outside sources show KOP has wrong,
 * and everything it needs is on the card:
 *
 *   - the finding, and KOP as it is now: each record the card is about side
 *     by side (names, status, years, companies), read live, not as researched
 *   - the steps, ticked or not: every change (record fields, past names,
 *     company links, a new company, notes, staff roles, merges as a rename or
 *     as one program, map years and map lines, memorial entries), each saying
 *     whether it is ready, already so, or moved since the research (shown with
 *     the value the record has now, unticked), with the quote that backs it;
 *     "Make the ticked changes" makes only those, a merge last
 *   - what is left to check (never made, only read) and the few steps done by
 *     hand (ticking one says it is done)
 *   - the sources: each quote in full, how much of the page was read, and a
 *     link that opens the page at the quote (a text fragment) or a preview
 *   - "Edit details": the records' names, status, years, companies and notes,
 *     saved through the site's own editor, each save with its own Undo
 *
 * A rename that explains why the years disagree (the proposal's 'renames', or
 * a renamed pair Merge Duplicates finds for its record) is on the card in
 * words, with "Merge as a rename" while both names are records of their own.
 * When one of the two is only the company's name (kop_daudit_rename_brands())
 * the card says so and offers to fold it in as one program instead; when the
 * names carry their companies' names, those companies' years date the rename.
 *
 * Keys: the proposal's key (the flag id, or transferred-<facility id>).
 * Tool: approve every high-confidence proposal whose steps are all ready.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('data-audit', function () {
    if (!function_exists('kop_daudit_proposals')) return null;
    return array(
        'label'    => 'Data audit',
        'group'    => 'Suggestions to check',
        'help'     => 'Facility data that news, court and SEC filings and licensing records show is wrong: closing years, status, companies, names, map years and people. Each card shows KOP as it is now, the steps to correct it (tick the ones you want) and the quotes they rest on. "Make the ticked changes" puts them on the site at once; "Edit details" changes a record by hand right there. Undo on the Approved tab.',
        'views'    => array('waiting' => 'Waiting', 'applied' => 'Approved', 'rejected' => 'Rejected'),
        'count'    => function () {
            return kop_rinbox_daudit_view_counts()['waiting'];
        },
        'view_counts' => 'kop_rinbox_daudit_view_counts',
        'tools'    => array(
            array('id' => 'approve_high', 'label' => 'Approve every high-confidence correction', 'style' => 'approve',
                'help' => 'Makes, as proposed, every ready step of every high-confidence card with nothing to do by hand. Cards with a step that moved since the research are left for you.',
                'confirm' => 'Approve every high-confidence correction as proposed? Each can be undone on the Approved tab.'),
        ),
        'tool'     => 'kop_rinbox_daudit_tool',
        'list'     => 'kop_rinbox_daudit_list',
        'on_file_in_list' => true,
        // The records' fields, on the card (kop_ie_facility_save(), as the pencil saves them).
        'save'     => 'kop_rinbox_daudit_save',
        'no_ai'    => true,
        'get'      => function ($key) {
            $p = kop_daudit_proposals()[$key] ?? null;
            return $p ? kop_rinbox_daudit_item((string) $key, $p) : null;
        },
        'act'      => 'kop_rinbox_daudit_act',
    );
});

function kop_rinbox_daudit_view($p, array $d) {
    $decision = (string) ($d['decision'] ?? '');
    return $decision !== '' ? $decision : 'waiting';
}

function kop_rinbox_daudit_view_counts() {
    $decisions = kop_daudit_decisions();
    $counts = array('waiting' => 0, 'applied' => 0, 'rejected' => 0);
    foreach (kop_daudit_proposals() as $key => $p) {
        $v = kop_rinbox_daudit_view($p, $decisions[$key] ?? array());
        if (isset($counts[$v])) $counts[$v]++;
    }
    return $counts;
}

function kop_rinbox_daudit_has_manual(array $p) {
    foreach ($p['ops'] as $op) if (($op['type'] ?? '') === 'manual') return true;
    return false;
}

function kop_rinbox_daudit_tool($id, array $params) {
    if ($id !== 'approve_high') throw new RuntimeException('Unknown tool.');
    $decisions = kop_daudit_decisions();
    $done = 0;
    $left = array();
    foreach (kop_daudit_proposals() as $key => $p) {
        if (($p['confidence'] ?? '') !== 'high' || kop_rinbox_daudit_view($p, $decisions[$key] ?? array()) !== 'waiting' || kop_rinbox_daudit_has_manual($p)) continue;
        try {
            kop_daudit_apply($key, kop_rinbox_reviewer());
            $done++;
        } catch (RuntimeException $e) {
            $left[] = (string) ($p['title'] ?? $key);
        }
    }
    if (!$done && !$left) return array('message' => 'No high-confidence corrections are waiting.');
    return array('message' => 'Approved ' . $done . ' correction' . ($done === 1 ? '' : 's') . '.'
        . ($left ? ' Left for you (changed since, or needs a look): ' . implode(', ', array_slice($left, 0, 8)) . (count($left) > 8 ? ' and ' . (count($left) - 8) . ' more' : '') . '.' : ''));
}

function kop_rinbox_daudit_list(array $q) {
    $decisions = kop_daudit_decisions();
    $rank = array('high' => 0, 'medium' => 1, 'low' => 2);
    $rows = array();
    foreach (kop_daudit_proposals() as $key => $p) {
        if (kop_rinbox_daudit_view($p, $decisions[$key] ?? array()) !== $q['view']) continue;
        if ($q['search'] !== '') {
            $hay = mb_strtolower(($p['title'] ?? '') . ' ' . ($p['place'] ?? '') . ' ' . ($p['finding'] ?? '') . ' ' . ($p['kop_now'] ?? ''));
            if (mb_strpos($hay, mb_strtolower($q['search'])) === false) continue;
        }
        $rows[(string) $key] = $p;
    }
    // High confidence first; one program's cards together.
    uksort($rows, function ($x, $y) use ($rows, $rank) {
        return ($rank[$rows[$x]['confidence'] ?? 'low'] ?? 3) <=> ($rank[$rows[$y]['confidence'] ?? 'low'] ?? 3)
            ?: strcasecmp((string) ($rows[$x]['title'] ?? $x), (string) ($rows[$y]['title'] ?? $y));
    });
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit'], true) as $key => $p) $items[] = kop_rinbox_daudit_item((string) $key, $p);
    return array('items' => $items, 'total' => count($rows));
}

/** Names the step lines use: records, map names, companies, people; '_main' = the card's record. */
function kop_rinbox_daudit_names(array $p, array $steps) {
    $names = array('_main' => (int) ($p['facility_id'] ?? 0));
    $graph = function_exists('kop_network_map_graph') ? kop_network_map_graph() : null;
    $nodes = array();
    foreach ((array) ($graph['nodes'] ?? array()) as $n) $nodes[(string) $n['id']] = (string) ($n['name'] ?? $n['id']);
    try {
        $pdo = kop_rinbox_pdo();
        global $wpdb;
        foreach (kop_daudit_card_records(array('ops' => array_values($steps)) + $p) as $fid) {
            $st = $pdo->prepare('SELECT name FROM facilities_v2 WHERE id = ?');
            $st->execute(array($fid));
            $n = $st->fetchColumn();
            if ($n !== false) $names['rec:' . $fid] = (string) $n;
        }
        foreach ($steps as $op) {
            if ($op['type'] === 'map_years' && isset($nodes[$op['node']])) $names['node:' . $op['node']] = $nodes[$op['node']];
            if ($op['type'] === 'operator_link' || $op['type'] === 'operator_field') {
                $st = $pdo->prepare("SELECT name FROM `{$wpdb->prefix}kop_operators` WHERE id = ?");
                $st->execute(array((int) $op['operator_id']));
                $names['op:' . $op['operator_id']] = (string) $st->fetchColumn();
            }
            if ($op['type'] === 'person_merge') {
                foreach (array('keep', 'drop') as $k) {
                    $st = $pdo->prepare("SELECT name FROM `{$wpdb->prefix}kop_people` WHERE id = ?");
                    $st->execute(array((int) $op[$k]));
                    $names['person:' . $op[$k]] = (string) $st->fetchColumn();
                }
            }
        }
    } catch (Throwable $e) {
        // Names are a help only; the ids still show.
    }
    return array_filter($names, function ($v) { return $v !== '' && $v !== 0; });
}

/* ---- The sources: each quote, how much was read, a link to the quote ---- */

/** How much of a source the research read, from the words its quote carries. */
function kop_rinbox_daudit_reach($quote) {
    $q = strtolower((string) $quote);
    if (strpos($q, 'title only') !== false || strpos($q, 'page not opened') !== false) return 'Title only: the page was not opened';
    if (preg_match('/returned 40\d|refuses|refused|blocked/', $q)) return 'The page refused the research fetch: read from a search summary';
    if (strpos($q, 'search summary') !== false || strpos($q, 'search snippet') !== false) return 'Read from a search summary, not the page itself';
    return 'Quoted from the page';
}

/** The quote's own words, without the research's asides ("(search summary)", "[...]"). */
function kop_rinbox_daudit_quote_words($quote) {
    $q = preg_replace('/\((?:search summary|search snippet|lead|page returned[^)]*|title only[^)]*)\)/i', ' ', (string) $quote);
    $q = preg_replace('/title only; page not opened\.?/i', ' ', $q);
    return trim(preg_replace('/\s+/', ' ', $q));
}

/**
 * The link that opens the page at the quote: a text fragment (#:~:text=) made
 * of the quote's first words up to an ellipsis, when the quote is the page's
 * own words and the link has no fragment of its own.
 */
function kop_rinbox_daudit_jump_url($url, $quote) {
    $url = (string) $url;
    if (strpos($url, '#') !== false || kop_rinbox_daudit_reach($quote) !== 'Quoted from the page') return $url;
    $words = kop_rinbox_daudit_quote_words($quote);
    $words = preg_split('/\s*(?:\.\.\.|…|\[)\s*/u', $words)[0];
    $words = trim($words, " \t\"'“”‘’:;,.");
    $list = preg_split('/\s+/', $words);
    if (count($list) < 4) return $url;
    $text = implode(' ', array_slice($list, 0, 10));
    return $url . '#:~:text=' . rawurlencode($text);
}

/** The card's sources: [{n, title, url, jump, host, date, quote, reach}]. */
function kop_rinbox_daudit_sources(array $p) {
    $out = array();
    foreach ((array) ($p['sources'] ?? array()) as $s) {
        if (empty($s['url'])) continue;
        $quote = trim((string) ($s['quote'] ?? ''));
        $out[] = array(
            'n'     => count($out) + 1,
            'title' => trim((string) ($s['title'] ?? '')) ?: (string) $s['url'],
            'url'   => (string) $s['url'],
            'jump'  => kop_rinbox_daudit_jump_url($s['url'], $quote),
            'host'  => preg_replace('/^www\./', '', (string) wp_parse_url((string) $s['url'], PHP_URL_HOST)),
            'date'  => in_array(trim((string) ($s['date'] ?? '')), array('', 'n.d.'), true) ? '' : trim((string) $s['date']),
            'quote' => $quote,
            'reach' => kop_rinbox_daudit_reach($quote),
        );
    }
    return $out;
}

/**
 * Where in the sources a step comes from: the source whose quote shares the
 * most of the step's years and names, with the words around the first one
 * ("Source 2: ...bought Turn-About Ranch in March 2000..."), or ''.
 */
function kop_rinbox_daudit_backing($words, array $sources) {
    preg_match_all('/\b(?:1[89]|20)\d\d\b/', $words, $years);
    preg_match_all('/\b[A-Z][a-zA-Z\'-]{3,}\b/', $words, $caps);
    $skip = array_flip(array('Add', 'Take', 'Record', 'Status', 'Closing', 'Opening', 'Merge', 'Network', 'This', 'The', 'Past', 'Other', 'Names',
        'Unlink', 'None', 'Open', 'Closed', 'Unknown', 'Suspended', 'Current', 'Years', 'Company', 'Move', 'From', 'Into', 'Name', 'Loving', 'Memory'));
    $tokens = array();
    foreach (array_unique($years[0]) as $y) $tokens[$y] = 2;
    foreach (array_unique($caps[0]) as $c) if (!isset($skip[$c])) $tokens[$c] = 1;
    if (!$tokens) return '';
    $best = null;
    foreach ($sources as $s) {
        $q = kop_rinbox_daudit_quote_words($s['quote']);
        if ($q === '') continue;
        $score = 0;
        $first = -1;
        $hits = array(2 => 0, 1 => 0);
        foreach ($tokens as $t => $w) {
            $at = stripos($q, $t);
            if ($at === false) continue;
            $score += $w;
            $hits[$w]++;
            if ($first < 0 || $at < $first) $first = $at;
        }
        // A year and a name, or three names: two shared words ("Three Springs") say little.
        $enough = ($hits[2] && $hits[1]) || $hits[1] >= 3;
        if ($enough && (!$best || $score > $best['score'])) $best = array('score' => $score, 'n' => $s['n'], 'q' => $q, 'at' => $first);
    }
    if (!$best) return '';
    $start = max(0, $best['at'] - 90);
    $snip = mb_substr($best['q'], $start, 240);
    return 'Source ' . $best['n'] . ': "' . ($start > 0 ? '...' : '') . trim($snip) . (mb_strlen($best['q']) > $start + 240 ? '...' : '') . '"';
}

/* ---- KOP now: the records side by side, read live ----------------------- */

function kop_rinbox_daudit_compare(array $fids, array $opts) {
    $heads = array();
    $cols = array();
    foreach (array_slice($fids, 0, 5) as $fid) {
        $stored = kop_facility_load($fid, $opts);
        $now = kop_daudit_resolve($fid);
        if (!$stored) {
            $heads[] = 'Record #' . $fid;
            $cols[] = array('name' => $now !== $fid ? 'Merged into #' . $now : 'No longer on file');
            continue;
        }
        $doc = $stored['doc'];
        $id = (array) ($doc['identification'] ?? array());
        $op = (array) ($doc['operatingPeriod'] ?? array());
        $loc = (array) ($doc['location'] ?? array());
        $links = array();
        try {
            $st = $opts['pdo']->prepare("SELECT o.name, f.relationship FROM `{$opts['prefix']}kop_operator_facilities` f JOIN `{$opts['prefix']}kop_operators` o ON o.id = f.operator_id WHERE f.facility_id = ? ORDER BY f.sort_order");
            $st->execute(array($fid));
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) $links[] = $r[0] . ' (' . $r[1] . ')';
        } catch (Throwable $e) {
            // The links are a help.
        }
        $years = kop_daudit_years_text(array('start' => (int) ($op['startYear'] ?? 0), 'end' => (int) ($op['endYear'] ?? 0)));
        $heads[] = (string) ($id['name'] ?? '') . ' (#' . $fid . ')';
        $cols[] = array(
            'name'     => (string) ($id['name'] ?? ''),
            'place'    => trim(implode(', ', array_filter(array((string) ($loc['city'] ?? ''), (string) ($loc['state'] ?? ''))))),
            'status'   => (string) ($op['status'] ?? ''),
            'years'    => $years . (trim((string) ($op['yearsOfOperation'] ?? '')) !== '' ? ' (written: ' . $op['yearsOfOperation'] . ')' : ''),
            'now'      => (string) ($id['currentName'] ?? ''),
            'past'     => kop_daudit_show(kop_daudit_list($doc, 'identification.pastNames')),
            'other'    => kop_daudit_show(kop_daudit_list($doc, 'identification.otherNames')),
            'operator' => (string) ($id['currentOperator'] ?? ''),
            'pastops'  => kop_daudit_show(kop_daudit_list($doc, 'identification.pastOperators')),
            'links'    => $links ? implode('; ', $links) : 'none',
        );
    }
    $labels = array('name' => 'Name', 'place' => 'Place', 'status' => 'Status', 'years' => 'Years', 'now' => 'Name it uses now', 'past' => 'Past names',
        'other' => 'Other names', 'operator' => 'Operator', 'pastops' => 'Past operators', 'links' => 'Company links');
    $rows = array();
    foreach ($labels as $k => $label) {
        $values = array_map(function ($c) use ($k) { return (string) ($c[$k] ?? ''); }, $cols);
        if (!array_filter($values, function ($v) { return $v !== '' && $v !== 'none'; })) continue;
        $rows[] = array('label' => $label, 'values' => $values, 'differs' => count($cols) > 1 && count(array_unique($values)) > 1 && in_array($k, array('status', 'years', 'operator'), true));
    }
    return $rows ? array('heads' => $heads, 'rows' => $rows) : null;
}

/* ---- The card ------------------------------------------------------------ */

function kop_rinbox_daudit_kinds() {
    return array(
        'rename_end_year' => 'Renamed program', 'end_before_items' => 'Closing year', 'status_years' => 'Status',
        'closure_ordered' => 'Closure', 'operator_profile' => 'Operator', 'operator_inherited' => 'Operator after a sale',
        'person_spelling' => 'Same person?', 'person_common' => 'One person or several?', 'memorial' => 'Memorial',
        'transferred' => 'Transferred to a new company', 'sequel_history' => 'Sequel program history',
        'company_record' => 'Company record', 'rename_years' => 'Renamed: years disagree',
    );
}

/** A step's line under it on the card: where it stands now. */
function kop_rinbox_daudit_state_words(array $s, array $op) {
    switch ($s['state']) {
        case 'done': return 'Already so on the site: nothing to do.';
        case 'changed': return 'Moved since the research: ' . $s['why'] . ' Tick it to make the change over what the record has now.';
        case 'blocked': return 'Cannot be made: ' . $s['why'];
        case 'manual': return 'Done by hand, outside this card (the site has no button for it). Tick it once it is done.';
    }
    if ($op['type'] === 'merge' && !empty($op['rename']) && empty($op['year'])) return 'Ready: give the year of the rename in the box below.';
    if ($op['type'] === 'merge') return 'Ready. Made after the other ticked changes; Undo splits the records again.';
    return 'Ready.';
}

function kop_rinbox_daudit_item($key, array $p) {
    $d = kop_daudit_decisions()[$key] ?? array();
    $view = kop_rinbox_daudit_view($p, $d);
    $waiting = $view === 'waiting';
    try {
        $opts = kop_daudit_opts();
    } catch (Throwable $e) {
        $opts = null;
    }
    $steps = kop_daudit_steps($p);
    $states = $opts ? kop_daudit_step_states($p, $opts) : array();
    $names = kop_rinbox_daudit_names($p, $steps);
    $sources = kop_rinbox_daudit_sources($p);
    $title = (string) ($p['title'] ?? $key);

    $checklist = array();
    $checks = array();
    $year_params = array();
    $merged_pairs = array();
    foreach ($steps as $i => $op) {
        $words = kop_daudit_op_words($op, $names);
        if ($op['type'] === 'merge') $merged_pairs[min((int) $op['keep'], (int) $op['drop']) . ':' . max((int) $op['keep'], (int) $op['drop'])] = true;
        $why = trim((string) ($op['step_text'] ?? ($op['text'] ?? '')));
        if ($op['type'] === 'check') {
            $checks[] = $words . ($why !== '' && $why !== $words ? ' (' . $why . ')' : '');
            continue;
        }
        $s = $states[$i] ?? array('state' => 'ready', 'why' => '');
        $notes = array();
        if (!empty($op['auto'])) $notes[] = 'Found on the record: ' . $op['why'];
        elseif ($why !== '' && $why !== $words && !in_array($op['type'], array('note', 'note_remove', 'manual'), true)) $notes[] = 'Why: ' . $why;
        $back = kop_rinbox_daudit_backing($words . ' ' . $why, $sources);
        if ($back !== '') $notes[] = $back;
        $checklist[] = array('keys' => array((string) $i), 'label' => ($op['type'] === 'manual' ? 'By hand: ' : '') . $words,
            'sub' => kop_rinbox_daudit_state_words($s, $op), 'note' => implode("\n", $notes),
            'checked' => $waiting && $s['state'] === 'ready');
        if ($waiting && $op['type'] === 'merge' && !empty($op['rename']) && empty($op['year']) && $s['state'] === 'ready') {
            $year_params[] = array('name' => 'year_' . $i, 'label' => 'Rename year (#' . (int) $op['drop'] . ' to #' . (int) $op['keep'] . ')', 'type' => 'number', 'value' => '');
        }
    }

    $details = array();
    $renames = kop_rinbox_daudit_renames((string) $key, $p);
    foreach ($renames as $r) {
        $details[] = array('label' => 'Renamed', 'value' => kop_daudit_rename_words($r['state']) . ($r['why'] !== '' ? ' (' . $r['why'] . ')' : ''));
        foreach ($r['brands']['lines'] as $line) $details[] = array('label' => 'Company names', 'value' => $line);
    }
    foreach ($checks as $c) $details[] = array('label' => 'To check', 'value' => $c);
    if (!empty($p['notes'])) $details[] = array('label' => 'Research note', 'value' => (string) $p['notes']);
    if (!empty($p['kop_now'])) $details[] = array('label' => 'KOP when researched', 'value' => (string) $p['kop_now']);
    if (!$waiting) {
        $details[] = array('label' => $view === 'applied' ? 'Approved' : 'Rejected', 'value' => trim(($d['at'] ?? '') . ' by ' . ($d['by'] ?? '')));
    }
    foreach ((array) ($d['edits'] ?? array()) as $n => $e) {
        $details[] = array('label' => 'Edited here', 'value' => $e['at'] . ' by ' . $e['by'] . ': ' . implode('; ', array_map(function ($c) {
            return kop_daudit_field_label($c['path']) . ' ' . kop_daudit_show($c['before']) . ' -> ' . kop_daudit_show($c['after']);
        }, $e['changes'])) . (!empty($e['undone']) ? ' (undone)' : ''));
    }

    // The decision.
    $ready = count(array_filter($states, function ($s) { return $s['state'] === 'ready'; }));
    if ($view === 'applied' && !empty($d['by_merge'])) {
        // Approved by merging the rename: its Undo is the merge's own (below).
        $actions = array();
    } elseif (!$waiting) {
        $actions = array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => $view === 'applied'
                ? 'Puts back what KOP had before for ' . $title . ' (anything edited since stays); the card waits for review again.'
                : 'Nothing on the site changes; the card waits for review again.'));
    } elseif (!$checklist) {
        // A rename alone (the flag script's rename_years cards): merging it is the approval.
        $actions = array(array('id' => 'reject', 'label' => 'Not one program: keep both records', 'style' => 'reject',
            'help' => 'Nothing on the site changes; the card moves to Rejected.'));
    } else {
        $open = count(array_filter($states, function ($s) { return in_array($s['state'], array('ready', 'changed', 'manual'), true); }));
        $actions = array(
            !$open ? array('id' => 'approve', 'label' => 'Already on the site: file it', 'style' => 'approve',
                'help' => 'Every step on this card is already so on the site; this files it under Approved without changing anything.') :
            array('id' => 'approve', 'label' => 'Make the ticked changes', 'style' => 'approve', 'params' => $year_params,
                'help' => 'Makes the ticked steps on ' . $title . ' on the site at once (' . $ready . ' ready'
                    . (count($checklist) > $ready ? ', the rest are unticked: already so, moved since, or by hand' : '') . '); unticked ones stay as they are. Undo is on the Approved tab.'),
            array('id' => 'reject', 'label' => 'Keep KOP as it is', 'style' => 'reject',
                'help' => 'Nothing on the site changes; the card moves to Rejected.'),
        );
    }
    // A pair the steps already merge needs no second merge button.
    $renames = array_filter($renames, function ($r) use ($merged_pairs) {
        list($e, $l) = array_map('intval', explode('>', $r['pair']));
        return !isset($merged_pairs[min($e, $l) . ':' . max($e, $l)]);
    });
    $rename_actions = kop_rinbox_daudit_rename_actions($renames);
    $actions = $checklist ? array_merge($actions, $rename_actions) : array_merge($rename_actions, $actions);
    if (array_filter((array) ($d['edits'] ?? array()), function ($e) { return empty($e['undone']); })) {
        $actions[] = array('id' => 'edit_undo', 'label' => 'Undo my last edit here', 'style' => 'undo',
            'help' => 'Puts back the fields the last "Edit details" save on this card changed (any changed again since stay).');
    }

    $item = array(
        'key'          => $key,
        'title'        => $title,
        'subtitle'     => implode(' · ', array_filter(array(kop_rinbox_daudit_kinds()[$p['kind'] ?? ''] ?? '', (string) ($p['place'] ?? ''), 'confidence: ' . ($p['confidence'] ?? 'low')))),
        'text'         => (string) ($p['finding'] ?? ''),
        'details'      => $details,
        'sources'      => $sources,
        'status'       => $view,
        'status_label' => array('waiting' => 'Waiting', 'applied' => 'Approved', 'rejected' => 'Rejected')[$view] ?? $view,
        'created'      => (string) ($d['at'] ?? ''),
        'facility'     => !empty($p['facility_id']) ? kop_rinbox_facility((int) $p['facility_id']) : null,
        'actions'      => $actions,
        'fields'       => $opts ? kop_rinbox_daudit_fields($p, $opts) : array(),
        'row_tools'    => array('off' => true),
    );
    if ($checklist) {
        $item['checklist'] = $checklist;
        $item['checklist_noun'] = 'changes';
        $item['checklist_full'] = true;
    }
    if ($opts && ($compare = kop_rinbox_daudit_compare(kop_daudit_card_records(array('ops' => array_values($steps)) + $p), $opts))) {
        $item['compare'] = $compare;
        $item['compare_first'] = true;
    }
    return $item;
}

function kop_rinbox_daudit_act($key, $action, array $params) {
    switch ($action) {
        case 'approve':
            $picked = isset($params['picked']) ? array_map('strval', (array) $params['picked']) : null;
            $years = array();
            foreach ($params as $k => $v) if (strpos((string) $k, 'year_') === 0) $years[substr((string) $k, 5)] = (int) $v;
            return array('message' => kop_daudit_apply($key, kop_rinbox_reviewer(), false, $picked, $years));
        case 'reject':
            return array('message' => kop_daudit_reject($key, kop_rinbox_reviewer()));
        case 'undo':
            return array('message' => kop_daudit_undo($key));
        case 'rename_merge':
            return array('message' => kop_daudit_rename_merge($key, (string) ($params['pair'] ?? ''), (int) ($params['year'] ?? 0), kop_rinbox_reviewer()));
        case 'rename_merge_reversed':
            return array('message' => kop_daudit_rename_merge($key, (string) ($params['pair'] ?? ''), (int) ($params['year'] ?? 0), kop_rinbox_reviewer(), true));
        case 'rename_fold':
            return array('message' => kop_daudit_rename_fold($key, (string) ($params['pair'] ?? ''), kop_rinbox_reviewer()));
        case 'rename_unmerge':
            return array('message' => kop_daudit_rename_unmerge($key, (string) ($params['pair'] ?? '')));
        case 'edit_undo':
            return array('message' => kop_daudit_edit_undo($key));
    }
    throw new RuntimeException('Unknown action.');
}

/* ---- Edit details: the records' fields, saved as the pencil saves them -- */

/** path => [label, type for the card, the pencil's group]. */
function kop_rinbox_daudit_edit_paths() {
    return array(
        'identification.currentName'      => array('Name it uses now', 'text', 'names'),
        'identification.pastNames'        => array('Past names', 'list', 'names'),
        'identification.otherNames'       => array('Other names', 'list', 'names'),
        'operatingPeriod.status'          => array('Status', 'select', 'status'),
        'operatingPeriod.startYear'       => array('Opened (year)', 'number', 'status'),
        'operatingPeriod.endYear'         => array('Closed (year)', 'number', 'status'),
        'operatingPeriod.yearsOfOperation' => array('Years as written', 'text', 'status'),
        'operatingPeriod.notes'           => array('Notes on its years', 'list', 'status'),
        'identification.currentOperator'  => array('Operator', 'text', 'people'),
        'identification.pastOperators'    => array('Past operators', 'list', 'people'),
        'identification.otherOperators'   => array('Other operators', 'list', 'people'),
    );
}

/** The card's editable fields: each record's names, status, years, companies and notes ("r<id>|<path>"). */
function kop_rinbox_daudit_fields(array $p, array $opts) {
    $out = array();
    $fids = array_slice(kop_daudit_card_records(array('ops' => array_values(kop_daudit_steps($p)))+ $p), 0, 4);
    foreach ($fids as $fid) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) continue;
        $label = (string) ($stored['doc']['identification']['name'] ?? ('#' . $fid)) . ' (#' . $fid . '): ';
        foreach (kop_rinbox_daudit_edit_paths() as $path => $def) {
            $v = kop_daudit_get($stored['doc'], $path);
            $f = array('name' => 'r' . $fid . '|' . $path, 'label' => (count($fids) > 1 ? $label : '') . $def[0], 'type' => $def[1]);
            if ($def[1] === 'list') $v = array_values(array_filter(kop_daudit_list($stored['doc'], $path), 'is_string'));
            if ($def[1] === 'select') {
                $f['options'] = array_combine(array('Open', 'Closed', 'Suspended', 'Transferred', 'Unknown'), array('Open', 'Closed', 'Suspended', 'Transferred', 'Unknown'));
            }
            $f['value'] = $v === null ? '' : $v;
            $out[] = $f;
        }
    }
    return $out;
}

function kop_rinbox_daudit_save($key, array $fields) {
    return array('message' => kop_daudit_edit_save((string) $key, $fields, kop_rinbox_reviewer()));
}

/**
 * Save the card's "Edit details" form: only fields that differ from the
 * record, per record and pencil group through kop_ie_facility_save(), logged
 * on the decision for Undo. Returns the message.
 */
function kop_daudit_edit_save($key, array $fields, $reviewer) {
    if (!isset(kop_daudit_proposals()[$key])) throw new RuntimeException('That proposal is not in the list any more.');
    if (!function_exists('kop_ie_facility_save')) throw new RuntimeException('The record editor is not loaded.');
    $opts = kop_daudit_opts();
    $defs = kop_rinbox_daudit_edit_paths();
    $by = array();
    foreach ($fields as $name => $value) {
        if (!preg_match('/^r(\d+)\|(.+)$/', (string) $name, $m) || !isset($defs[$m[2]])) continue;
        $by[(int) $m[1]][$m[2]] = $value;
    }
    $log = array();
    foreach ($by as $fid => $values) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) throw new RuntimeException('Record #' . $fid . ' is gone (merged or deleted).');
        $groups = array();
        $before = array();
        foreach ($values as $path => $value) {
            $def = $defs[$path];
            $now = kop_daudit_get($stored['doc'], $path);
            if ($def[1] === 'list') {
                $list = array_values(array_filter(array_map('trim', array_map('strval', is_array($value) ? $value : preg_split('/\R/', (string) $value))), 'strlen'));
                if ($list === array_values(array_filter(array_map('trim', array_map('strval', array_filter(kop_daudit_list($stored['doc'], $path), 'is_string'))), 'strlen'))) continue;
                $value = $path === 'operatingPeriod.notes' ? $list : implode("\n", $list);
            } else {
                $value = trim((string) $value);
                if (kop_daudit_norm($value) === kop_daudit_norm($now)) continue;
            }
            $groups[$def[2]][$path] = $value;
            $before[$path] = $now;
        }
        foreach ($groups as $group => $vals) kop_ie_facility_save(array($fid, $group), $vals);
        if (!$before) continue;
        $after = kop_facility_load($fid, $opts);
        $changes = array();
        foreach ($before as $path => $was) $changes[] = array('path' => $path, 'before' => $was, 'after' => kop_daudit_get($after['doc'], $path));
        $log[] = array('facility_id' => $fid, 'changes' => $changes);
    }
    if (!$log) return 'Nothing was different from the records, so nothing was saved.';
    $decisions = kop_daudit_decisions();
    $all = array();
    foreach ($log as $l) foreach ($l['changes'] as $c) $all[] = $c + array('facility_id' => $l['facility_id']);
    $decisions[$key]['edits'][] = array('at' => gmdate('Y-m-d H:i:s'), 'by' => (string) $reviewer, 'changes' => $all);
    kop_daudit_save_decisions($decisions);
    return 'Saved ' . count($all) . ' field' . (count($all) === 1 ? '' : 's') . ' on the record' . (count($log) === 1 ? '' : 's') . '. "Undo my last edit here" puts them back.';
}

/** Undo the card's last "Edit details" save, leaving fields changed again since. */
function kop_daudit_edit_undo($key) {
    $decisions = kop_daudit_decisions();
    $edits = (array) ($decisions[$key]['edits'] ?? array());
    $n = null;
    foreach ($edits as $i => $e) if (empty($e['undone'])) $n = $i;
    if ($n === null) throw new RuntimeException('No edit on this card to undo.');
    $opts = kop_daudit_opts();
    $kept = array();
    $by = array();
    foreach ($edits[$n]['changes'] as $c) $by[(int) $c['facility_id']][] = $c;
    foreach ($by as $fid => $changes) {
        kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $changes, $opts, &$kept) {
            $stored = kop_facility_load($fid, $opts);
            if (!$stored) {
                $kept[] = 'record #' . $fid . ' (gone)';
                return;
            }
            $doc = $stored['doc'];
            $status = kop_daudit_get($doc, 'operatingPeriod.status');
            foreach ($changes as $c) {
                if (wp_json_encode(kop_daudit_get($doc, $c['path'])) === wp_json_encode($c['after'])) kop_daudit_set($doc, $c['path'], $c['before']);
                else $kept[] = kop_daudit_field_label($c['path']) . ' (changed again since)';
            }
            kop_wbf_save($doc, $opts);
            if (kop_daudit_get($doc, 'operatingPeriod.status') !== $status) do_action('kop_facility_status_changed', $fid);
        });
    }
    $decisions[$key]['edits'][$n]['undone'] = true;
    kop_daudit_save_decisions($decisions);
    return 'Edit undone' . ($kept ? '; left as changed since: ' . implode(', ', $kept) : '') . '.';
}

/* ---- Renames -------------------------------------------------------------- */

/** The card's renames: pair => {pair, why, found, state (kop_daudit_rename_state()), brands (kop_daudit_rename_brands())}. */
function kop_rinbox_daudit_renames($key, array $p) {
    $pairs = kop_daudit_renames($p);
    if (!$pairs) return array();
    try {
        $opts = kop_daudit_opts();
    } catch (Throwable $e) {
        return array();
    }
    $out = array();
    foreach ($pairs as $pair => $r) {
        $state = kop_daudit_rename_state($key, $r, $opts['pdo']);
        $brands = $state['state'] === 'separate' ? kop_daudit_rename_brands($state, $opts) : array('company_side' => '', 'company' => '', 'backwards' => false, 'year' => 0, 'lines' => array());
        $out[$pair] = array('pair' => $pair, 'why' => $r['found'] ? (string) $r['why'] : '', 'found' => $r['found'], 'state' => $state, 'brands' => $brands);
    }
    return $out;
}

/**
 * "Merge as a rename" (both ways round) while both names are records of their
 * own; "One program: fold it in" when one is only the company's name; the
 * merge's Undo once made from the card. The order the company names' years
 * give comes first, with their year.
 */
function kop_rinbox_daudit_rename_actions(array $renames) {
    $merge = array();
    $flipped = array();
    $fold = array();
    $undo = array();
    $year = '';
    $flip_year = '';
    $backwards = false;
    foreach ($renames as $pair => $r) {
        $s = $r['state'];
        $b = $r['brands'] ?? array();
        if ($s['state'] === 'separate') {
            $merge[$pair] = 'Keep ' . $s['later']['name'] . ' (#' . $s['later']['id'] . '); ' . $s['earlier']['name'] . ' (#' . $s['earlier']['id']
                . ') becomes its earlier name' . ($s['year'] ? ', renamed ' . $s['year'] : '');
            $flipped[$pair] = 'Keep ' . $s['earlier']['name'] . ' (#' . $s['earlier']['id'] . '); ' . $s['later']['name'] . ' (#' . $s['later']['id']
                . ') becomes its earlier name';
            if (!empty($b['company_side'])) {
                $keep = $b['company_side'] === 'later' ? $s['earlier'] : $s['later'];
                $drop = $b['company_side'] === 'later' ? $s['later'] : $s['earlier'];
                $fold[$pair] = 'Keep ' . $keep['name'] . ' (#' . $keep['id'] . '); ' . $drop['name'] . ' (#' . $drop['id'] . ') folds into it';
            }
            if (!empty($b['backwards'])) {
                $backwards = true;
                if ($flip_year === '' && $b['year']) $flip_year = (string) $b['year'];
            }
            if ($year === '') $year = $s['year'] ? (string) $s['year'] : (!empty($b['year']) && empty($b['backwards']) ? (string) $b['year'] : '');
        } elseif ($s['state'] === 'merged') {
            $undo[$pair] = 'Undo: ' . ($s['later']['name'] ?? ('#' . $s['later_id'])) . ' and record #' . $s['earlier_id'] . ' apart again';
        }
    }
    $actions = array();
    if ($fold) {
        $actions[] = array('id' => 'rename_fold', 'label' => 'One program: fold the company-named record in', 'style' => 'approve',
            'help' => 'The record under the company\'s own name is the same program: it folds into the other as one name (no earlier name, no years of its own); everything filed under it moves over and its page forwards. Undo is on this card.',
            'confirm' => 'Fold the company-named record into the other as one program? You can undo it from this card.',
            'params' => array(array('name' => 'pair', 'label' => 'Records', 'type' => 'select', 'value' => (string) array_key_first($fold), 'options' => $fold)));
    }
    if ($merge) {
        $straight = array('id' => 'rename_merge', 'label' => 'Merge as a rename', 'style' => 'approve',
            'help' => 'One program under two names: the earlier name\'s record folds into the later one\'s and becomes its past name, keeping its own years, status, company and everything filed under it; the page is cut into one section per name and the old page forwards. The map\'s rename line gets the year. Undo is on this card.',
            'confirm' => 'Merge these two records as one program renamed in the year given? You can undo it from this card.',
            'params' => array(
                array('name' => 'pair', 'label' => 'Records', 'type' => 'select', 'value' => (string) array_key_first($merge), 'options' => $merge),
                array('name' => 'year', 'label' => 'Year of the rename' . (count($merge) > 1 ? ' (blank = as listed)' : ''), 'type' => 'number',
                    'value' => count($merge) > 1 ? '' : $year),
            ));
        // The order was guessed from the records' years and can be backwards.
        $other = array('id' => 'rename_merge_reversed', 'label' => $backwards ? 'Merge as a rename, the other way round (what the company years say)' : 'Merge the other way round', 'style' => 'approve',
            'help' => 'The same merge with the names the other way round: the name listed as later is the earlier one. Its record folds into the other and becomes its past name, with its own years and everything filed under it. Undo is on this card.',
            'confirm' => 'Merge these two records as one program, the other way round, renamed in the year given? You can undo it from this card.',
            'params' => array(
                array('name' => 'pair', 'label' => 'Records', 'type' => 'select', 'value' => (string) array_key_first($flipped), 'options' => $flipped),
                array('name' => 'year', 'label' => 'Year of the rename', 'type' => 'number', 'value' => $flip_year),
            ));
        $actions = array_merge($actions, $backwards ? array($other, $straight) : array($straight, $other));
    }
    if ($undo) {
        $actions[] = array('id' => 'rename_unmerge', 'label' => 'Undo the merge', 'style' => 'undo',
            'help' => 'Splits the two records again, each with everything it had before the merge.',
            'params' => array(array('name' => 'pair', 'label' => 'Merge', 'type' => 'select', 'value' => (string) array_key_first($undo), 'options' => $undo)));
    }
    return $actions;
}
