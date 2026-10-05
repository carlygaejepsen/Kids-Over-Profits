<?php
/**
 * Review inbox source: links from the owner's Google Docs, HEAL's archived
 * site, the r/troubledteens wiki and SCIAD NET (inc/drive-docs.php). Add puts
 * a link where its kind belongs through kop_gdl_apply() (resource link or
 * website on the facility record, or the news, lawsuits or legislation
 * queue); "Move to" sends it somewhere else; Undo is kop_gdl_undo().
 *
 * Everything the old screen (KOP Tools > Drive Docs) does is here: the tabs
 * with their counts, the source ("Came from") and kind filters, sure matches
 * ticked to start with, Add to another record (the Record box on Add, also in
 * bulk), "Add all sure matches" for a facility (on the card and as a queue
 * tool, in batches), and loading the links files again.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('drive-docs', function () {
    if (!function_exists('kop_gdl_apply')) return null;
    $kinds = kop_gdl_kinds();
    $sources = array();
    foreach (kop_gdl_sources() as $k => $s) $sources[$k] = $s['label'];
    return array(
        'label'    => 'Drive Docs',
        'group'    => 'Imports to review',
        'help'     => 'Links found in your Google Docs, HEAL\'s old site, the r/troubledteens wiki and SCIAD NET that the site does not have yet. '
            . 'Add sends each one where its kind belongs (news to the news queue, court records and bills to their queues, the program\'s own site to its website links, '
            . 'everything else to "Materials and links" on the facility page). To put it on another record, pick it in the Record box beside Add. '
            . 'Each card is one facility with all its links; links from one website are folded into one row. Tick what belongs and click "Add ticked links" '
            . '(close-name matches start unticked). News, court records and bills go straight onto the site, with no second approval in their queues. '
            . 'Undo in Recently done takes back a whole click. "Show: One card per link" gives a card per link, with "Move to" and edits. No emails are sent.'
            . (kop_gdl_paths() ? '' : ' No links uploaded yet: run python scripts/gdocs-extract.py and copy tmp/gdocs/links.json to ' . dirname(kop_gdl_path()) . '.'),
        'filters'  => array(
            array('name' => 'kind', 'label' => 'Kind', 'options' => $kinds),
            array('name' => 'layout', 'label' => 'Show', 'options' => array('' => 'One card per facility', 'single' => 'One card per link')),
        ),
        'view_counts' => 'kop_rinbox_gdl_view_counts',
        'tools'    => array(
            array('id' => 'add_all', 'label' => 'Add every sure match for a facility', 'style' => 'approve',
                'help' => 'Adds every waiting link tied for sure to this facility, each where its kind goes, a batch at a time. Click again if it says some are left.',
                'params' => array(
                    array('name' => 'facility', 'label' => 'Facility', 'type' => 'facility', 'value' => ''),
                    array('name' => 'kind', 'label' => 'Only', 'type' => 'select', 'options' => array('' => 'Every kind') + $kinds, 'value' => ''),
                    array('name' => 'source', 'label' => 'From', 'type' => 'select', 'options' => array('' => 'Every source') + $sources, 'value' => ''),
                )),
            array('id' => 'resync', 'label' => 'Load the links files again', 'style' => 'neutral',
                'help' => 'Reads the uploaded links files now, even if they look unchanged: new links are added, waiting ones take the latest match.'),
        ),
        'tool'     => 'kop_rinbox_gdl_tool',
        'views'    => array(
            'pending'  => 'Waiting',
            'facility' => 'Waiting, for a facility',
            'company'  => 'Waiting, company only',
            'none'     => 'Waiting, no facility',
            'applied'  => 'Added',
            'rejected' => 'Skipped or already there',
        ),
        'tool_url' => admin_url('admin.php?page=kop-drive-docs'),
        'count'    => function () {
            global $wpdb;
            kop_gdl_ensure_table();
            return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_gdl_table() . " WHERE status = 'pending'");
        },
        'list'     => 'kop_rinbox_gdl_list',
        'get'      => function ($key) {
            if (strncmp((string) $key, 'g:', 2) === 0) return kop_rinbox_gdl_group_item($key);
            $rows = kop_gdl_rows(array($key));
            return $rows ? kop_rinbox_gdl_item($rows[0]) : null;
        },
        'origins'  => 'kop_rinbox_gdl_origins',
        'act'      => 'kop_rinbox_gdl_act',
        'save'     => 'kop_rinbox_gdl_save',
        'ai_fill'  => 'kop_rinbox_gdl_ai_name',
    );
});

function kop_rinbox_gdl_list(array $q) {
    global $wpdb;
    kop_gdl_ensure_table();
    if ($q['view'] === 'pending' && !get_transient('kop_rinbox_gdl_synced')) {
        // New links files are loaded when the queue is opened, as the old screen does.
        set_transient('kop_rinbox_gdl_synced', 1, MINUTE_IN_SECONDS);
        kop_gdl_sync();
    }
    if (kop_rinbox_gdl_grouped($q)) return kop_rinbox_gdl_group_list($q);
    $where = kop_rinbox_gdl_where($q['view'], $q);
    $table = kop_gdl_table();
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $order = in_array($q['view'], array('applied', 'rejected'), true)
        ? 'reviewed_at DESC, id DESC'
        : 'facility_id = 0, facility_id, ' . kop_gdl_kind_order_sql() . ', label, id';
    $rows = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset'], ARRAY_A);
    return array('items' => array_map('kop_rinbox_gdl_item', $rows), 'total' => $total);
}

/** A view's rows under the "Came from", kind and search filters ($q as 'list' gets it). */
function kop_rinbox_gdl_where($view, array $q) {
    global $wpdb;
    $tabs = kop_gdl_tabs();
    $where = $view === 'pending' ? "status = 'pending'" : ($tabs[$view]['where'] ?? "status = 'pending'");
    if (!empty($q['origin']) && isset(kop_gdl_sources()[$q['origin']])) {
        $where .= $wpdb->prepare(' AND source = %s', $q['origin']);
    }
    if (!empty($q['filters']['kind'])) {
        $where .= $wpdb->prepare(' AND kind = %s', $q['filters']['kind']);
    }
    if (($q['search'] ?? '') !== '') {
        $like = '%' . $wpdb->esc_like($q['search']) . '%';
        $where .= $wpdb->prepare(' AND (label LIKE %s OR url LIKE %s OR source_doc LIKE %s OR operator_name LIKE %s)', $like, $like, $like, $like);
    }
    return $where;
}

/** How many links each tab holds under the same filters, as the old screen's tab counts. */
function kop_rinbox_gdl_view_counts(array $q) {
    global $wpdb;
    $out = array();
    foreach (array('pending', 'facility', 'company', 'none', 'applied', 'rejected') as $v) {
        $out[$v] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_gdl_table() . ' WHERE ' . kop_rinbox_gdl_where($v, $q));
    }
    return $out;
}

/** Waiting links tied for sure to $fid (kop_gdl_card_all_where), optionally one kind and one source. */
function kop_rinbox_gdl_sure_where($fid, $kind = '', $src = '') {
    return kop_gdl_card_all_where((int) $fid, array('kind' => isset(kop_gdl_kinds()[$kind]) ? $kind : '',
        'src' => isset(kop_gdl_sources()[$src]) ? $src : '', 'q' => ''));
}

/**
 * "Add all" of the old screen's facility card (its apply_card): every sure
 * match for $fid, each where its kind goes, 40 at a time until none wait or
 * $seconds pass. Returns [added, refused, left].
 */
function kop_rinbox_gdl_add_all($fid, $kind = '', $src = '', $seconds = 25) {
    global $wpdb;
    $fid = (int) $fid;
    if ($fid <= 0) throw new RuntimeException('Pick the facility first.');
    $where = kop_rinbox_gdl_sure_where($fid, $kind, $src);
    $table = kop_gdl_table();
    $user = kop_rinbox_reviewer();
    $added = $refused = 0;
    $started = time();
    $last = -1;
    do {
        $batch = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY id LIMIT 40", ARRAY_A);
        if (!$batch) break;
        foreach (kop_gdl_apply($batch, array(), $fid, $user) as $res) {
            if (!empty($res['ok'])) $added++;
            else $refused++;
        }
        $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        if ($left === $last) break; // nothing moved: stop rather than loop
        $last = $left;
    } while ($left > 0 && time() - $started < $seconds);
    $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    kop_rinbox_flush_counts();
    return array($added, $refused, $left);
}

function kop_rinbox_gdl_add_all_message($fid, array $r) {
    list($added, $refused, $left) = $r;
    return 'Added ' . $added . ' to ' . kop_rinbox_facility($fid)['name'] . ' (each where its kind goes)'
        . ($refused ? ', ' . $refused . ' already on file or refused (now under "Skipped or already there")' : '')
        . ($left ? '; ' . $left . ' still to go: click again to carry on.' : '.');
}

function kop_rinbox_gdl_tool($id, array $params) {
    if ($id === 'add_all') {
        $fid = (int) ($params['facility'] ?? 0);
        $res = kop_rinbox_gdl_add_all($fid, (string) ($params['kind'] ?? ''), (string) ($params['source'] ?? ''));
        return array('message' => kop_rinbox_gdl_add_all_message($fid, $res));
    }
    if ($id === 'resync') {
        $sync = kop_gdl_sync(true);
        delete_transient('kop_rinbox_gdl_synced');
        if (!$sync) return array('message' => kop_gdl_paths() ? 'The links files could not be read (one may be half copied). Try again in a minute.' : 'No links files are uploaded yet.');
        return array('message' => 'Loaded the links files: ' . (int) $sync['added'] . ' new, ' . (int) $sync['updated'] . ' updated, ' . (int) $sync['gone'] . ' no longer offered.');
    }
    throw new RuntimeException('Unknown tool.');
}

/** "Came from": Google Docs, HEAL archive, r/troubledteens wiki, SCIAD NET, with counts in the view. */
function kop_rinbox_gdl_origins(array $q) {
    global $wpdb;
    $tabs = kop_gdl_tabs();
    $where = $q['view'] === 'pending' ? "status = 'pending'" : ($tabs[$q['view']]['where'] ?? "status = 'pending'");
    $counts = array();
    foreach ((array) $wpdb->get_results('SELECT source, COUNT(*) AS n FROM ' . kop_gdl_table() . " WHERE {$where} GROUP BY source", ARRAY_A) as $r) {
        $counts[(string) $r['source']] = (int) $r['n'];
    }
    $out = array();
    foreach (kop_gdl_sources() as $key => $src) {
        if (!empty($counts[$key])) $out[] = array('key' => $key, 'label' => $src['label'], 'count' => $counts[$key]);
    }
    return $out;
}

function kop_rinbox_gdl_item(array $r) {
    $kinds = kop_gdl_kinds();
    $targets = kop_gdl_targets();
    $sources = kop_gdl_sources();
    $src = $sources[$r['source'] ?? ''] ?? array();
    $seen = json_decode((string) $r['seen'], true) ?: array();
    $first = $seen[0] ?? array();
    $done = json_decode((string) $r['applied'], true) ?: array();
    $pending = $r['status'] === 'pending';

    $lines = array();
    if ($pending && (int) $r['facility_id'] > 0 && !kop_gdl_sure_match($r)) {
        $lines[] = 'Matched by a close name (' . $r['facility_how'] . '): check it is this facility.';
    }
    if ($pending && (int) $r['facility_id'] === 0 && $r['operator_name'] !== '') {
        $lines[] = 'Company: ' . $r['operator_name'] . '. Pick the facility before adding it to a facility page.';
    }
    $also = json_decode((string) $r['also_named'], true) ?: array();
    if ($also) {
        $lines[] = (($r['source'] ?? '') === 'sciad' && (int) $r['facility_id'] === 0 ? 'Same name as these records, pick one: ' : 'Also names: ')
            . implode(', ', array_map(function ($a) {
                return ($a['name'] ?? '') . (!empty($a['state']) ? ' (' . $a['state'] . ')' : '') . (!empty($a['id']) ? ' #' . (int) $a['id'] : '');
            }, array_slice($also, 0, 5)));
    }
    if (!empty($done['reason'])) {
        $lines[] = $done['reason'];
    } elseif (!empty($done['target'])) {
        $lines[] = 'Went to: ' . ($targets[$done['target']] ?? $done['target']) . (!empty($done['id']) ? ' #' . (int) $done['id'] : '') . '.';
    }

    $links = array();
    if (!empty($first['archive']) && preg_match('#^https?://#i', (string) $first['archive'])) {
        $links[] = array('label' => 'Archived copy', 'url' => (string) $first['archive']);
    }
    if (!empty($src['credit_url'])) {
        $links[] = array('label' => 'Credit: ' . $src['label'], 'url' => $src['credit_url']);
    }

    $default = kop_gdl_default_target($r['kind']);
    $sure = $pending && kop_gdl_sure_match($r);
    $actions = array();
    $moves = array();
    if ($pending) {
        $fac = kop_rinbox_facility($r['facility_id']);
        $fac_name = $fac ? $fac['name'] : 'the record you pick';
        if ($default === 'resource') {
            $add_help = 'Adds this link to the facility page of ' . $fac_name . ' under "Materials and links".';
        } elseif ($default === 'website') {
            $add_help = 'Adds this link to the website links of ' . $fac_name . '.';
        } else {
            $add_help = 'Adds this link to the ' . strtolower($targets[$default]) . ' already approved, so it is on the site at once with no second approval there (the hourly AI reader still fills in its details). No email is sent.';
        }
        // The Record box is the old screen's "Add checked to that record": another record than the match.
        $actions[] = array('id' => 'apply', 'label' => 'Add: ' . $targets[$default], 'style' => 'approve', 'help' => $add_help, 'params' => array(
            array('name' => 'facility', 'label' => kop_gdl_needs_facility($default) ? 'Record' : 'Record (not needed for a queue)',
                'type' => 'facility', 'value' => (int) $r['facility_id'], 'optional' => true),
        ));
        if ($sure) {
            global $wpdb;
            $n = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_gdl_table() . ' WHERE ' . kop_rinbox_gdl_sure_where((int) $r['facility_id']));
            if ($n > 1) {
                $actions[] = array('id' => 'apply_all', 'label' => 'Add all ' . $n . ' sure matches for this facility', 'style' => 'neutral',
                    'confirm' => 'Add all ' . $n . ' links tied for sure to this facility, each where its kind goes?',
                    'help' => 'Adds every waiting link matched for sure to ' . $fac_name . ', each to the place its kind goes (facility page, website links or a review queue).');
            }
        }
        $actions[] = array('id' => 'reject', 'label' => 'Reject: skip this link', 'style' => 'reject',
            'help' => 'Nothing is added anywhere; the link moves to the Skipped tab.');
        foreach ($targets as $id => $label) {
            if ($id === $default) continue;
            $m = array('id' => $id, 'label' => $label);
            if (kop_gdl_needs_facility($id)) {
                $m['params'] = array(array('name' => 'facility', 'label' => 'Record', 'type' => 'facility', 'value' => (int) $r['facility_id']));
            }
            $moves[] = $m;
        }
    } elseif ($r['status'] === 'applied') {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => 'Takes the link off where it was added and puts it back in the waiting list.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => 'Back to review', 'style' => 'neutral',
            'help' => 'Nothing on the site changes; the link waits for review again.');
    }
    $statuses = array('pending' => 'Waiting', 'applied' => 'Added', 'rejected' => 'Skipped', 'gone' => 'No longer offered');
    $fid = $r['status'] === 'applied' ? (int) $r['applied_fid'] : (int) $r['facility_id'];

    $details = array();
    if ($pending) $details[] = array('label' => 'Goes to', 'value' => $targets[$default] . ' (change it with "Move to")');
    if ((int) $r['facility_id'] > 0 && $r['facility_how'] !== '') {
        $details[] = array('label' => 'Matched by', 'value' => $r['facility_how'] . ($sure ? ' (sure match)' : ''));
    }
    if ($r['operator_name'] !== '') $details[] = array('label' => 'Company', 'value' => (string) $r['operator_name']);
    if (!empty($src['credit'])) $details[] = array('label' => 'Credit on the record', 'value' => $src['credit'], 'url' => $src['credit_url'] ?? '');
    if ((string) ($r['original'] ?? '') !== '' && $r['original'] !== $r['url']) $details[] = array('label' => 'Address as written', 'value' => (string) $r['original']);

    return array(
        'key'          => (string) $r['pkey'],
        'title'        => $r['label'] !== '' ? (string) $r['label'] : (string) $r['url'],
        'subtitle'     => implode(' · ', array_filter(array($kinds[$r['kind']] ?? $r['kind'], $r['domain'], $src['label'] ?? ''))),
        'url'          => (string) $r['url'],
        'text'         => implode("\n", $lines),
        'created'      => (string) $r['created_at'],
        'status'       => (string) $r['status'],
        'status_label' => $statuses[$r['status']] ?? $r['status'],
        'facility'     => kop_rinbox_facility($fid),
        'fields'       => array(
            array('name' => 'label', 'label' => 'Label', 'type' => 'text', 'value' => (string) $r['label'], 'readonly' => !$pending),
            array('name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => $kinds, 'value' => (string) $r['kind'], 'category' => true, 'readonly' => !$pending),
            array('name' => 'facility_id', 'label' => 'Facility', 'type' => 'facility', 'value' => (int) $r['facility_id'], 'readonly' => !$pending),
        ),
        'actions'      => $actions,
        'moves'        => $moves,
        'links'        => $links,
        'details'      => $details,
        'selected'     => $sure,
    );
}

/**
 * "Name it with AI": reads the page or PDF the link points to (or text the reviewer pasted) and replaces
 * the label with a short neutral title, since labels copied from the docs are notes, not titles. A link
 * still marked "Other" also gets a kind. Saved through kop_rinbox_gdl_save().
 */
function kop_rinbox_gdl_ai_name($key, $text = '') {
    if (strncmp((string) $key, 'g:', 2) === 0) throw new RuntimeException('Use "Name it with AI" on one link of the card.');
    $r = kop_rinbox_gdl_row($key);
    if ($r['status'] !== 'pending') throw new RuntimeException('Undo it (or put it back to review) before editing.');
    $page = trim((string) $text);
    if ($page === '' && kop_rinbox_load_enrich()) {
        try {
            $page = trim((string) kop_enrich_document_text((string) $r['url']));
        } catch (Throwable $e) {
            $page = '';
        }
    }
    if ($page === '') {
        throw new RuntimeException('The AI could not open this link. Rename it by hand, or open it as its own card ("Show: one card per link") and paste its text.');
    }
    $kinds = kop_gdl_kinds();
    $ask_kind = (string) $r['kind'] === 'other';
    $prompt = "You name links for Kids Over Profits, a site documenting abuse in the troubled teen industry.\n"
        . "Write a short, neutral, factual title (at most 90 characters) for the page below: for a news article or court record, its own headline or case title; "
        . "otherwise say plainly what the page is (\"Instagram account of <program>\", \"<program> listing on <site>\").\n"
        . "Use only what the page says. No crude words, no insults, no opinions. Never name a young person.\n"
        . 'Answer with one JSON object and nothing else: {"label": "..."' . ($ask_kind ? ', "kind": one of ' . implode(', ', array_keys($kinds)) : '') . "}\n\n"
        . 'Address: ' . $r['url'] . "\n\nPage text:\n" . mb_substr($page, 0, 12000);
    kop_rinbox_load_ai();
    $answer = kop_ai_extract_json(kop_ai_generate_alternating($prompt, array('maxTokens' => 300, 'temperature' => 0.1)));
    $label = is_array($answer) ? trim(preg_replace('/\s+/u', ' ', (string) ($answer['label'] ?? ''))) : '';
    if ($label === '') throw new RuntimeException('The AI answer was not readable. Try again.');
    $fields = array('label' => mb_substr($label, 0, 200));
    $kind = is_array($answer) ? (string) ($answer['kind'] ?? '') : '';
    if ($ask_kind && $kind !== '' && $kind !== 'other' && isset($kinds[$kind])) $fields['kind'] = $kind;
    kop_rinbox_gdl_save($key, $fields);
    return array('message' => 'Named "' . $fields['label'] . '"' . (isset($fields['kind']) ? ' (' . $kinds[$fields['kind']] . ')' : '') . '.', 'label' => $fields['label']);
}

/** The row, or a helpful error. */
function kop_rinbox_gdl_row($key) {
    $rows = kop_gdl_rows(array($key));
    if (!$rows) throw new RuntimeException('That link is gone from the Drive Docs list.');
    return $rows[0];
}

function kop_rinbox_gdl_act($key, $action, array $params) {
    global $wpdb;
    if (strncmp((string) $key, 'g:', 2) === 0) return kop_rinbox_gdl_group_act($key, $action, $params);
    $r = kop_rinbox_gdl_row($key);
    $user = kop_rinbox_reviewer();
    $targets = kop_gdl_targets();
    switch ($action) {
        case 'apply':
        case 'move':
            $to = $action === 'move' ? (string) ($params['to'] ?? '') : kop_gdl_default_target($r['kind']);
            if (!isset($targets[$to])) throw new RuntimeException('Pick where the link goes.');
            if ($r['status'] !== 'pending') throw new RuntimeException('This link was already handled (' . $r['status'] . ').');
            $fid = (int) $r['facility_id'];
            $picked = (int) ($params['facility'] ?? $params['facility_id'] ?? 0);
            if ($picked > 0 && $picked !== $fid) {
                // Another record than the match: kop_gdl_apply() takes it as the record, as "Add checked to that record" did.
                $st = kop_rinbox_pdo()->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = ?');
                $st->execute(array($picked));
                if (!(int) $st->fetchColumn()) throw new RuntimeException('Facility #' . $picked . ' does not exist.');
                $fid = $picked;
            }
            if (kop_gdl_needs_facility($to) && $fid <= 0) {
                throw new RuntimeException('Pick the facility first: choose it in the Record box beside Add, then add it.');
            }
            $res = kop_gdl_apply(array($r), array($r['pkey'] => $to), $fid, $user)[$r['pkey']] ?? array('ok' => false, 'error' => 'Nothing was done.');
            if (empty($res['ok'])) {
                if (!empty($res['keep'])) throw new RuntimeException($res['error']);
                return array('message' => 'Not added: ' . $res['error'] . ' It is now under "Skipped or already there".');
            }
            $where = kop_gdl_needs_facility($to)
                ? ($to === 'website' ? 'the website links of ' : 'the "Materials and links" list of ') . kop_rinbox_facility($fid)['name']
                : 'the ' . strtolower($targets[$to]) . ', approved and on the site now (no emails sent)';
            return array('message' => 'Added to ' . $where . '. Undo is on the Added tab.');
        case 'apply_all':
            if ($r['status'] !== 'pending' || !kop_gdl_sure_match($r)) throw new RuntimeException('This link is not a waiting sure match.');
            $fid = (int) $r['facility_id'];
            return array('message' => kop_rinbox_gdl_add_all_message($fid, kop_rinbox_gdl_add_all($fid)));
        case 'reject':
            if ($r['status'] !== 'pending') throw new RuntimeException('Only a waiting link can be skipped.');
            $wpdb->update(kop_gdl_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => 'Skipped')),
                'reviewed_by' => $user, 'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
            return array('message' => 'Skipped. Nothing was added; "Back to review" on the Skipped tab brings it back.');
        case 'undo':
            $was = $r['status'];
            $res = kop_gdl_undo(array($r), $user)[$r['pkey']] ?? array('ok' => false, 'error' => 'Nothing to undo.');
            if (empty($res['ok'])) throw new RuntimeException($res['error']);
            return array('message' => $was === 'applied' ? 'Undone: the link came off where it went. It is waiting for review again.' : 'Waiting for review again.');
    }
    throw new RuntimeException('Unknown action.');
}

function kop_rinbox_gdl_save($key, array $fields) {
    global $wpdb;
    $r = kop_rinbox_gdl_row($key);
    if ($r['status'] !== 'pending') throw new RuntimeException('Undo it (or put it back to review) before editing.');
    $set = array();
    if (array_key_exists('label', $fields)) {
        $set['label'] = trim(preg_replace('/\s+/u', ' ', (string) $fields['label']));
    }
    if (isset($fields['kind']) && $fields['kind'] !== '') {
        if (!isset(kop_gdl_kinds()[$fields['kind']])) throw new RuntimeException('Pick one of the kinds in the list.');
        $set['kind'] = (string) $fields['kind'];
    }
    if (array_key_exists('facility_id', $fields)) {
        $fid = (int) $fields['facility_id'];
        if ($fid !== (int) $r['facility_id']) {
            if ($fid > 0) {
                $st = kop_rinbox_pdo()->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = ?');
                $st->execute(array($fid));
                if (!(int) $st->fetchColumn()) throw new RuntimeException('Facility #' . $fid . ' does not exist.');
            }
            $set['facility_id'] = max(0, $fid);
            // Picked by a person: a sure match (kop_gdl_sure_match), so the old screen ticks it too.
            $set['facility_how'] = $fid > 0 ? 'picked by ' . mb_substr(kop_rinbox_reviewer(), 0, 28) : '';
        }
    }
    if (!$set) return array('message' => 'Nothing to save.');
    $wpdb->update(kop_gdl_table(), $set, array('pkey' => $r['pkey']));
    kop_rinbox_flush_counts();
    return array('message' => 'Saved.');
}

/* ---- One card per facility --------------------------------------------- */

/*
 * The waiting tabs show one card per facility (a company's links with no
 * facility share a card, and so do the links of one doc with neither). The
 * card lists its links with a tick box each, links from one website folded
 * into one row; "Add ticked links" puts every ticked one where its kind goes,
 * "Skip ticked links" skips them. Close-name matches start unticked. One
 * Recently done entry per click, whose Undo takes back every link it did.
 * The Show filter's "One card per link" gives the single-link cards (Move to,
 * edits, the Record box per link).
 *
 * Card key: g:<f facility id | c md5(company) | d md5(doc)>:<kind>:<source>,
 * the kind and source filters the list had, so the card keeps showing what
 * the list showed.
 */

if (!defined('KOP_GDL_GROUP_ROWS')) {
    define('KOP_GDL_GROUP_ROWS', 300);
}

/** Does this list show one card per facility? */
function kop_rinbox_gdl_grouped(array $q) {
    return ($q['filters']['layout'] ?? '') !== 'single' && in_array($q['view'], array('pending', 'facility', 'company', 'none'), true);
}

function kop_rinbox_gdl_group_list(array $q) {
    global $wpdb;
    $table = kop_gdl_table();
    $where = kop_rinbox_gdl_where($q['view'], $q);
    $op = "CASE WHEN facility_id > 0 THEN '' ELSE operator_name END";
    $doc = "CASE WHEN facility_id > 0 OR operator_name <> '' THEN '' ELSE source_doc END";
    $group = "facility_id, {$op}, {$doc}";
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT 1 FROM {$table} WHERE {$where} GROUP BY {$group}) g");
    $list = (array) $wpdb->get_results("SELECT facility_id AS fid, {$op} AS op, {$doc} AS doc, COUNT(*) AS n FROM {$table} WHERE {$where} "
        . "GROUP BY {$group} ORDER BY facility_id = 0, COUNT(*) DESC, MIN(id) LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset'], ARRAY_A);
    $kind = (string) ($q['filters']['kind'] ?? '');
    $origin = (string) ($q['origin'] ?? '');
    $items = array();
    foreach ($list as $g) {
        $key = 'g:' . ((int) $g['fid'] > 0 ? 'f' . (int) $g['fid'] : ($g['op'] !== '' ? 'c' . md5((string) $g['op']) : 'd' . md5((string) $g['doc'])))
            . ':' . (isset(kop_gdl_kinds()[$kind]) ? $kind : '') . ':' . (isset(kop_gdl_sources()[$origin]) ? $origin : '');
        $item = kop_rinbox_gdl_group_item($key);
        if ($item) $items[] = $item;
    }
    return array('items' => $items, 'total' => $total);
}

/**
 * A card key as [SQL condition over its waiting links, facility id, card name],
 * or null when the key is not a card or nothing waits under it.
 */
function kop_rinbox_gdl_group_parse($key) {
    global $wpdb;
    if (!preg_match('/^g:([fcd])([0-9a-f]+):([a-z_]*):([a-z]*)$/', (string) $key, $m)) return null;
    $table = kop_gdl_table();
    $where = "status = 'pending'";
    $fid = 0;
    $name = '';
    if ($m[1] === 'f') {
        $fid = (int) $m[2];
        $where .= $wpdb->prepare(' AND facility_id = %d', $fid);
        $fac = kop_rinbox_facility($fid);
        $name = $fac ? $fac['name'] : 'Facility #' . $fid;
    } else {
        $col = $m[1] === 'c' ? 'operator_name' : 'source_doc';
        $base = $m[1] === 'c' ? "status = 'pending' AND facility_id = 0 AND operator_name <> ''" : "status = 'pending' AND facility_id = 0 AND operator_name = ''";
        $found = null;
        foreach ((array) $wpdb->get_col("SELECT DISTINCT {$col} FROM {$table} WHERE {$base}") as $v) {
            if (md5((string) $v) === $m[2]) { $found = (string) $v; break; }
        }
        if ($found === null) return null;
        $where = $base . $wpdb->prepare(" AND {$col} = %s", $found);
        $name = $m[1] === 'c' ? 'Company: ' . $found : 'No facility: ' . ($found !== '' ? $found : 'links with no doc name');
    }
    if ($m[3] !== '' && isset(kop_gdl_kinds()[$m[3]])) $where .= $wpdb->prepare(' AND kind = %s', $m[3]);
    if ($m[4] !== '' && isset(kop_gdl_sources()[$m[4]])) $where .= $wpdb->prepare(' AND source = %s', $m[4]);
    return array('where' => $where, 'fid' => $fid, 'name' => $name, 'company' => $m[1] === 'c');
}

/** Where a link goes when added, in words. */
function kop_rinbox_gdl_goes($kind) {
    $t = kop_gdl_default_target($kind);
    return array('news' => 'news, live at once', 'lawsuit' => 'lawsuits, live at once', 'legislation' => 'legislation, live at once',
        'website' => 'website links', 'resource' => '"Materials and links"')[$t] ?? $t;
}

/** "3 news articles, 1 court record": a kind count in words. */
function kop_rinbox_gdl_kind_words(array $counts) {
    $words = array(
        'news' => array('news article', 'news articles'), 'court' => array('court record', 'court records'),
        'legislation' => array('bill', 'bills'), 'inspection' => array('licensing report', 'licensing reports'),
        'government' => array('government page', 'government pages'), 'social' => array('survivor/social post', 'survivor/social posts'),
        'people' => array('person page', 'person pages'), 'advertising' => array('advertising listing', 'advertising listings'),
        'reference' => array('reference', 'references'), 'archive' => array('archive copy', 'archive copies'),
        'program_site' => array("program site page", "program site pages"), 'other' => array('other link', 'other links'),
    );
    $out = array();
    foreach ($counts as $k => $n) {
        $w = $words[$k] ?? array($k, $k);
        $out[] = $n . ' ' . $w[$n === 1 ? 0 : 1];
    }
    return implode(', ', $out);
}

function kop_rinbox_gdl_group_item($key) {
    global $wpdb;
    $g = kop_rinbox_gdl_group_parse($key);
    if (!$g) return null;
    $table = kop_gdl_table();
    $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$g['where']}");
    if (!$n) return null;
    $rows = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE {$g['where']} ORDER BY " . kop_gdl_kind_order_sql()
        . ', domain, label, id LIMIT ' . (int) KOP_GDL_GROUP_ROWS, ARRAY_A);
    $kinds = kop_gdl_kinds();
    $sources = kop_gdl_sources();

    // Links from one website (and as sure as each other) fold into one row.
    $bundles = array();
    $kind_counts = array();
    $doubtful = 0;
    $created = '';
    foreach ($rows as $r) {
        $sure = kop_gdl_sure_match($r);
        if ((int) $r['facility_id'] > 0 && !$sure) $doubtful++;
        $kind_counts[$r['kind']] = ($kind_counts[$r['kind']] ?? 0) + 1;
        if ($created === '' || $r['created_at'] < $created) $created = (string) $r['created_at'];
        $bkey = ($r['domain'] !== '' ? $r['domain'] : $r['url']) . '|' . ($sure ? 1 : 0);
        $bundles[$bkey][] = $r;
    }
    $describe = function (array $r) use ($kinds, $sources) {
        $seen = json_decode((string) $r['seen'], true) ?: array();
        $first = $seen[0] ?? array();
        $sub = ($kinds[$r['kind']] ?? $r['kind']) . ' · goes to ' . kop_rinbox_gdl_goes($r['kind'])
            . (!empty($sources[$r['source']]['label']) ? ' · ' . $sources[$r['source']]['label'] : '')
            . ((int) $r['facility_id'] > 0 && !kop_gdl_sure_match($r) ? ' · close name (' . $r['facility_how'] . '), check it' : '');
        // The words around the link in the doc are never shown: a link is named by its label alone.
        $note = '';
        return array('key' => (string) $r['pkey'], 'label' => $r['label'] !== '' ? (string) $r['label'] : (string) $r['url'],
            'url' => (string) $r['url'], 'sub' => $sub, 'note' => $note, 'rename' => true, 'kind' => (string) $r['kind']);
    };
    $checklist = array();
    foreach ($bundles as $list) {
        $sure = kop_gdl_sure_match($list[0]) || (int) $list[0]['facility_id'] === 0;
        if (count($list) === 1) {
            $e = $describe($list[0]);
            $checklist[] = array('keys' => array($e['key']), 'label' => $e['label'], 'url' => $e['url'], 'sub' => $e['sub'], 'note' => $e['note'], 'checked' => $sure, 'rename' => true, 'kind' => $e['kind']);
            continue;
        }
        $counts = array();
        foreach ($list as $r) $counts[$r['kind']] = ($counts[$r['kind']] ?? 0) + 1;
        $targets = array_unique(array_map(function ($r) { return kop_rinbox_gdl_goes($r['kind']); }, $list));
        $checklist[] = array(
            'keys'    => array_column($list, 'pkey'),
            'label'   => $list[0]['domain'] . ': ' . kop_rinbox_gdl_kind_words($counts),
            'sub'     => 'goes to ' . implode(' and ', $targets)
                . ((int) $list[0]['facility_id'] > 0 && !kop_gdl_sure_match($list[0]) ? ' · close name (' . $list[0]['facility_how'] . '), check it' : ''),
            'checked' => $sure,
            'items'   => array_map($describe, $list),
        );
    }

    $text = array();
    if ($n > count($rows)) $text[] = 'Showing the first ' . count($rows) . ' of ' . $n . ' links; the rest appear here once these are done.';
    if ($doubtful) $text[] = $doubtful . ' link' . ($doubtful > 1 ? 's were' : ' was') . ' matched by a close name and start' . ($doubtful > 1 ? '' : 's') . ' unticked: check they are about this facility.';
    if ($g['fid'] <= 0) $text[] = 'No facility yet: news, court records and bills can be added as they are; for the rest, pick the facility in the Record box first.';
    $where_to = $g['fid'] > 0 ? $g['name'] : 'the record you pick';

    return array(
        'key'          => (string) $key,
        'title'        => $g['name'],
        'subtitle'     => $n . ' link' . ($n > 1 ? 's' : '') . ': ' . kop_rinbox_gdl_kind_words($kind_counts),
        'url'          => '',
        'text'         => implode("\n", $text),
        'created'      => $created,
        'status'       => 'pending',
        'status_label' => 'Waiting',
        'facility'     => $g['fid'] > 0 ? kop_rinbox_facility($g['fid']) : null,
        'fields'       => array(),
        'checklist'    => $checklist,
        // Each link row edits its own name and kind and can be named by the AI (kop_rinbox_gdl_ai_name).
        'row_tools'    => array('kinds' => $kinds, 'ai' => true),
        'actions'      => array(
            array('id' => 'add_picked', 'label' => 'Add ticked links', 'style' => 'approve',
                'help' => 'Puts every ticked link where its kind goes, in one go: news, court records and bills straight onto the site (no second approval), '
                    . 'program sites to the website links and the rest to "Materials and links" of ' . $where_to . '. Undo in Recently done takes them all back.',
                'params' => array(array('name' => 'facility', 'label' => $g['fid'] > 0 ? 'Record' : 'Record (needed for facility-page links)',
                    'type' => 'facility', 'value' => $g['fid'], 'optional' => true))),
            array('id' => 'skip_picked', 'label' => 'Skip ticked links', 'style' => 'reject',
                'help' => 'Nothing is added anywhere; the ticked links move to the Skipped tab.'),
        ),
        'moves'        => array(),
        'links'        => array(),
        'details'      => array(),
        'selected'     => false,
    );
}

/** The card's waiting links among $picked (pkeys). */
function kop_rinbox_gdl_group_picked(array $g, $picked) {
    global $wpdb;
    $picked = array_values(array_unique(array_filter(array_map(function ($k) { return preg_replace('/[^a-f0-9]/', '', (string) $k); }, (array) $picked), 'strlen')));
    if (!$picked) throw new RuntimeException('Tick at least one link first.');
    $out = array();
    foreach (array_chunk($picked, 200) as $chunk) {
        $in = implode(',', array_map(function ($k) { return "'" . $k . "'"; }, $chunk));
        $out = array_merge($out, (array) $wpdb->get_results('SELECT * FROM ' . kop_gdl_table() . " WHERE {$g['where']} AND pkey IN ({$in}) ORDER BY id", ARRAY_A));
    }
    if (!$out) throw new RuntimeException('Those links were already handled. Reload the list.');
    return $out;
}

function kop_rinbox_gdl_group_act($key, $action, array $params) {
    global $wpdb;
    $user = kop_rinbox_reviewer();
    if ($action === 'undo_picked') {
        $keys = array_values(array_filter(array_map(function ($k) { return preg_replace('/[^a-f0-9]/', '', (string) $k); }, (array) ($params['keys'] ?? array())), 'strlen'));
        $rows = $keys ? kop_gdl_rows($keys) : array();
        if (!$rows) throw new RuntimeException('Nothing to undo.');
        $ok = $bad = 0;
        $why = '';
        foreach (kop_gdl_undo($rows, $user) as $res) {
            if (!empty($res['ok'])) $ok++;
            else { $bad++; $why = $why ?: (string) ($res['error'] ?? ''); }
        }
        kop_rinbox_flush_counts();
        return array('message' => 'Undone: ' . $ok . ' link' . ($ok === 1 ? '' : 's') . ' back in the waiting list'
            . ($bad ? '; ' . $bad . ' could not be taken back (' . $why . ')' : '') . '.');
    }
    $g = kop_rinbox_gdl_group_parse($key);
    if (!$g) throw new RuntimeException('Nothing waits on this card any more.');
    $rows = kop_rinbox_gdl_group_picked($g, $params['picked'] ?? array());
    if ($action === 'skip_picked') {
        $now = current_time('mysql', true);
        foreach ($rows as $r) {
            $wpdb->update(kop_gdl_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => 'Skipped')),
                'reviewed_by' => $user, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
        }
        kop_rinbox_flush_counts();
        return array('message' => 'Skipped ' . count($rows) . ' link' . (count($rows) === 1 ? '' : 's') . '. Nothing was added.',
            'undo' => array('action' => 'undo_picked', 'params' => array('keys' => array_column($rows, 'pkey'))));
    }
    if ($action !== 'add_picked') throw new RuntimeException('Unknown action.');
    $fid = $g['fid'];
    $picked = (int) ($params['facility'] ?? 0);
    if ($picked > 0 && $picked !== $fid) {
        $st = kop_rinbox_pdo()->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = ?');
        $st->execute(array($picked));
        if (!(int) $st->fetchColumn()) throw new RuntimeException('Facility #' . $picked . ' does not exist.');
        $fid = $picked;
    }
    // 40 at a time, for 25 seconds: a big card is finished with another click.
    $added = array();
    $refused = $need = $left = 0;
    $started = time();
    foreach (array_chunk($rows, 40) as $i => $batch) {
        if ($i && time() - $started > 25) { $left += count($batch); continue; }
        foreach (kop_gdl_apply($batch, array(), $fid, $user) as $pkey => $res) {
            if (!empty($res['ok'])) $added[] = (string) $pkey;
            elseif (!empty($res['keep'])) $need++;
            else $refused++;
        }
    }
    kop_rinbox_flush_counts();
    $msg = 'Added ' . count($added) . ' link' . (count($added) === 1 ? '' : 's') . ', each where its kind goes'
        . ($fid > 0 ? ' (facility links on ' . kop_rinbox_facility($fid)['name'] . ')' : '')
        . ($refused ? '; ' . $refused . ' already on file or refused (now under "Skipped or already there")' : '')
        . ($need ? '; ' . $need . ' need a facility: pick it in the Record box and add again' : '')
        . ($left ? '; ' . $left . ' not reached yet: click Add again' : '') . '.';
    $out = array('message' => $msg);
    if ($added) $out['undo'] = array('action' => 'undo_picked', 'params' => array('keys' => $added));
    return $out;
}
