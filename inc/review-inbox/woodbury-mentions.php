<?php
/**
 * Review inbox source: pages of the Woodbury Reports newsletter that write
 * about a program (inc/woodbury-mentions.php). Filing puts those pages, as
 * their own small PDF, in the program's "Woodbury Reports Mentions" folder
 * through kop_wb_file_many() (facility ids, or c<id> for a company); Skip
 * and Put back are kop_wb_set_status(); Undo is kop_wb_undo().
 *
 * Everything the old screen (KOP Tools > Woodbury Reports) does is here too:
 * the tabs with counts, the cut pages shown in the card (kop_wb_view), filing
 * under several facilities and companies (the best match's other possible
 * matches and parent companies are tick boxes, any company by name through
 * the 'company' lookup), filing under a consultant, company, provider or
 * transporter that has a record (kop_wbc_existing_target(), 'record'
 * lookup), creating the record and filing in one click (kop_wbc_create()),
 * on filed pages "Also file under" (kop_wb_add_facilities()) and taking one
 * extra record off (kop_wb_remove_facility()), articles with a confident
 * match ticked to start with, loading the scan again and removing the extra
 * copies a retried filing left (as admin_post_kop_wb_dedupe).
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('woodbury-reports', function () {
    if (!function_exists('kop_wb_file_many')) return null;
    $views = array('pending' => 'Waiting');
    foreach (kop_wb_tabs() as $k => $t) $views[$k] = $t['label'];
    return array(
        'label'    => 'Woodbury Reports',
        'group'    => 'Imports to review',
        'help'     => 'Pages of the Woodbury Reports newsletter that write about a program. "Show the pages" opens them here. '
            . 'Filing puts those pages, as their own small PDF, in a "Woodbury Reports Mentions" folder on each facility\'s page (and company page): '
            . 'the best match is filled in, tick its other possible matches or parent companies, or add any other facility or company. '
            . 'About an educational consultant, company, provider or transporter? Use "File under that record", or create the record and file in one click. '
            . 'Articles with a confident match start ticked. On the Filed tab, Undo takes the copy back out and "Also file under" adds more records.'
            . (is_readable(kop_wb_pending_dir() . '/candidates.json') ? '' : ' No scan uploaded yet: run python scripts/woodbury-scan.py and copy its pending folder to ' . kop_wb_pending_dir() . '.'),
        'views'    => $views,
        'view_counts' => 'kop_rinbox_wb_view_counts',
        'tool_url' => admin_url('admin.php?page=kop-woodbury-reports'),
        'tools'    => array(
            array('id' => 'resync', 'label' => 'Load the scan again', 'style' => 'neutral',
                'help' => 'Reads the uploaded candidates.json now, even if it looks unchanged: new pages are added, waiting ones take the latest match.'),
            array('id' => 'dedupe', 'label' => 'Remove extra copies of filed pages', 'style' => 'neutral',
                'confirm' => 'Remove the extra copies a retried filing left? One copy of each, the one filed in the program folder, is kept.',
                'help' => 'A filing retried while the first try was still running can import the same pages twice. This keeps one copy of each.'),
        ),
        'tool'     => 'kop_rinbox_wb_tool',
        'lookup'   => 'kop_rinbox_wb_lookup',
        'count'    => function () {
            global $wpdb;
            kop_wb_ensure_table();
            return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_wb_table() . " WHERE status = 'pending'");
        },
        'list'     => 'kop_rinbox_wb_list',
        'get'      => function ($key) {
            $r = kop_wb_get(preg_replace('/[^a-f0-9]/', '', (string) $key));
            return $r ? kop_rinbox_wb_item($r) : null;
        },
        'act'      => 'kop_rinbox_wb_act',
        'save'     => 'kop_rinbox_wb_save',
    );
});

function kop_rinbox_wb_kinds() {
    return array('section' => 'Article', 'fuzzy' => 'Article, close name', 'news' => 'News item', 'mention' => 'Mentioned', 'unmatched' => 'Article, no record');
}

/** A view's rows under the search (the old screen's Program name box). */
function kop_rinbox_wb_where($view, $search) {
    global $wpdb;
    $tabs = kop_wb_tabs();
    $where = isset($tabs[$view]) ? kop_wb_tab_where($view) : "status = 'pending'";
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where .= $wpdb->prepare(' AND (facility_name LIKE %s OR header LIKE %s OR matched_name LIKE %s)', $like, $like, $like);
    }
    return $where;
}

function kop_rinbox_wb_list(array $q) {
    global $wpdb;
    kop_wb_ensure_table();
    if ($q['view'] === 'pending' && !get_transient('kop_rinbox_wb_synced')) {
        // A new scan is loaded when the queue is opened, as the old screen does.
        set_transient('kop_rinbox_wb_synced', 1, MINUTE_IN_SECONDS);
        kop_wb_sync();
    }
    $where = kop_rinbox_wb_where($q['view'], $q['search']);
    $table = kop_wb_table();
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    $order = in_array($q['view'], array('filed', 'skipped'), true)
        ? 'reviewed_at DESC, id DESC'
        : "CASE kind WHEN 'section' THEN 0 WHEN 'fuzzy' THEN 1 WHEN 'news' THEN 2 WHEN 'mention' THEN 3 ELSE 4 END, facility_name = '', facility_name, header, issue_date, id";
    $rows = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY {$order} LIMIT " . (int) $q['limit'] . ' OFFSET ' . (int) $q['offset'], ARRAY_A);
    return array('items' => array_map('kop_rinbox_wb_item', $rows), 'total' => $total);
}

function kop_rinbox_wb_view_counts(array $q) {
    global $wpdb;
    $out = array();
    foreach (array_merge(array('pending'), array_keys(kop_wb_tabs())) as $v) {
        $out[$v] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_wb_table() . ' WHERE ' . kop_rinbox_wb_where($v, (string) ($q['search'] ?? '')));
    }
    return $out;
}

/** Parent companies of these facilities, guarded: [{id, name, of}]. */
function kop_rinbox_wb_parents(array $fids) {
    try {
        return kop_wb_parent_companies($fids);
    } catch (Throwable $e) {
        return array();
    }
}

/**
 * The "File under" params: the facilities box (the best match in it), a tick
 * box for each other possible match and each parent company, and a company
 * found by name. All optional, so "File" works on many selected cards.
 */
function kop_rinbox_wb_target_params(array $r, $best, array $alts, array $parents, $first_label) {
    $params = array();
    $start = $best > 0 ? array(array('id' => $best, 'name' => $r['facility_name'] . ($r['facility_state'] !== '' ? ' (' . $r['facility_state'] . ')' : ''))) : array();
    $params[] = array('name' => 'facilities', 'label' => $first_label, 'type' => 'facilities', 'value' => $start, 'optional' => true);
    $shown = array($best => true);
    foreach (array_slice($alts, 0, 5) as $a) {
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0 || isset($shown[$id])) continue;
        $shown[$id] = true;
        $params[] = array('name' => 'alt_' . $id, 'label' => 'Also ' . ($a['name'] ?? '#' . $id) . (!empty($a['state']) ? ' (' . $a['state'] . ')' : '') . ', other possible match',
            'type' => 'checkbox', 'value' => '', 'optional' => true);
    }
    foreach ($parents as $c) {
        $params[] = array('name' => 'co_' . (int) $c['id'], 'label' => $c['name'] . ' (company, ' . $c['of'] . ')', 'type' => 'checkbox', 'value' => '', 'optional' => true);
    }
    $params[] = array('name' => 'company', 'label' => 'And a company', 'type' => 'text', 'value' => '', 'optional' => true,
        'lookup' => 'company', 'placeholder' => 'Type a company name');
    return $params;
}

/** The tokens ("123", "c45") the target params name. */
function kop_rinbox_wb_tokens(array $params) {
    $tokens = array();
    $ids = $params['facilities'] ?? array();
    foreach (is_array($ids) ? $ids : preg_split('/[\s,;]+/', (string) $ids) as $id) {
        if (is_array($id)) $id = $id['id'] ?? '';
        if ((int) $id > 0) $tokens[] = (string) (int) $id;
    }
    // The single facility box older cards sent, and the "also" text box.
    if (!empty($params['facility'])) $tokens[] = (string) (int) $params['facility'];
    foreach ($params as $k => $v) {
        if (preg_match('/^alt_(\d+)$/', (string) $k, $m) && !empty($v) && $v !== '0') $tokens[] = $m[1];
        if (preg_match('/^co_(\d+)$/', (string) $k, $m) && !empty($v) && $v !== '0') $tokens[] = 'c' . $m[1];
    }
    foreach (preg_split('/[\s,;]+/', (string) ($params['company'] ?? '') . ' ' . (string) ($params['also'] ?? '')) as $t) {
        if (preg_match('/^company:(\d+)$/i', $t, $m)) $t = 'c' . $m[1];
        $t = kop_wb_clean_token($t);
        if ($t !== '') $tokens[] = $t;
    }
    return array_values(array_unique($tokens));
}

function kop_rinbox_wb_item(array $r) {
    $kinds = kop_rinbox_wb_kinds();
    $pending = $r['status'] === 'pending';
    $filed = $r['status'] === 'filed';
    $alts = json_decode((string) $r['alternatives'], true);
    $alts = is_array($alts) ? $alts : array();
    $said = $r['header'] !== '' ? $r['header'] . ($r['place'] !== '' ? ', ' . $r['place'] : '') : $r['matched_name'];
    $issue = 'Woodbury Reports, ' . $r['issue_label'] . ($r['issue_number'] !== '' ? ' (' . $r['issue_number'] . ')' : '') . ', ' . kop_wb_page_label($r['pages']);
    $snippet = trim((string) $r['snippet']);

    $lines = array();
    if ($said !== '') $lines[] = 'The pages say "' . $said . '".';
    if ($snippet !== '') $lines[] = '"' . kop_rinbox_excerpt($snippet, 600) . '"';
    if (trim((string) $r['note']) !== '') $lines[] = trim((string) $r['note']);
    if ($pending && $r['kind'] === 'fuzzy' && (int) $r['facility_id']) $lines[] = 'Close name: check it is this facility.';
    if ($pending && !(int) $r['facility_id']) $lines[] = 'No facility matched this name: pick one under "File under", file it under another kind of record, or create the record.';

    $details = array();
    if ($snippet !== '' && mb_strlen($snippet) > 600) $details[] = array('label' => 'All the words', 'value' => $snippet);
    $places = $filed ? kop_wb_places($r) : array();
    foreach ($places as $p) {
        $details[] = array('label' => $p['primary'] ? 'Filed under' : 'Also filed under',
            'value' => $p['name'] . ($p['kind'] === 'company' ? ' (company)' : '') . ($p['where'] !== '' ? ', folder ' . $p['where'] : ''),
            'url' => $p['page']);
    }
    if (!$pending && (string) $r['reviewed_by'] !== '') $details[] = array('label' => $filed ? 'Filed by' : 'Handled by', 'value' => (string) $r['reviewed_by']);

    $links = array();
    $preview = null;
    if ($filed && (int) $r['attachment_id']) {
        $u = wp_get_attachment_url((int) $r['attachment_id']);
        if ($u) {
            $links[] = array('label' => 'Filed PDF', 'url' => (string) $u);
            $preview = array('label' => 'Show the filed pages', 'url' => (string) $u);
        }
    } elseif ((string) $r['file'] !== '') {
        // The cut pages wait outside the web root; the old screen's viewer sends them to a signed-in admin.
        $view = add_query_arg(array('action' => 'kop_wb_view', 'key' => $r['ckey'], 'nonce' => wp_create_nonce('kop_woodbury')), admin_url('admin-ajax.php'));
        $links[] = array('label' => 'View pages', 'url' => $view);
        $preview = array('label' => 'Show the pages', 'url' => $view);
    }
    $issue_url = (int) $r['issue_id'] ? wp_get_attachment_url((int) $r['issue_id']) : '';
    if ($issue_url) $links[] = array('label' => 'Full issue', 'url' => $issue_url . '#page=' . (int) strtok((string) $r['pages'], ','));

    $actions = array();
    $best = (int) $r['facility_id'];
    if ($pending) {
        $best_name = $best ? kop_rinbox_facility($best)['name'] : 'the record you pick';
        $actions[] = array('id' => 'file', 'label' => 'Approve: file these pages', 'style' => 'approve',
            'help' => 'Saves these pages as a PDF in the "Woodbury Reports Mentions" folder of ' . $best_name . ' (or the records picked below), listed with its documents on its page.',
            'params' => kop_rinbox_wb_target_params($r, $best, $alts, $best ? kop_rinbox_wb_parents(array($best)) : array(), 'File under'));
        if (function_exists('kop_wbc_existing_target')) {
            $actions[] = array('id' => 'file_record', 'label' => 'File under that record', 'style' => 'neutral',
                'help' => 'Files the pages under the consultant, company, provider or transporter you name instead (and any facilities added).',
                'params' => array(
                array('name' => 'record', 'label' => 'A consultant, company, provider or transporter', 'type' => 'text', 'value' => '',
                    'lookup' => 'record', 'placeholder' => 'Name of the firm or person'),
                array('name' => 'facilities', 'label' => 'And these facilities', 'type' => 'facilities', 'value' => array(), 'optional' => true),
            ));
        }
        if (function_exists('kop_wbc_create')) {
            $p = kop_wbc_prefill($r);
            $states = array('' => '(none, or outside the US)');
            foreach (kop_wb_state_names() as $code => $name) $states[$code] = $code . ' ' . $name;
            $types = array('' => 'Not sure');
            foreach (function_exists('kop_facdisc_types') ? kop_facdisc_types() : array() as $t) $types[$t] = $t;
            $actions[] = array('id' => 'create', 'label' => 'Create the record and file the pages under it', 'style' => 'neutral',
                'help' => 'Makes a new record of the kind and name below, then files the pages under it.',
                'confirm' => 'Create a new record with this name and file the pages under it?', 'params' => array(
                array('name' => 'kind', 'label' => 'Not in the database? Kind', 'type' => 'select', 'options' => kop_wbc_kinds(), 'value' => ''),
                array('name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => $p['name']),
                array('name' => 'who', 'label' => 'A consultant is a', 'type' => 'select', 'options' => array('firm' => 'Firm', 'person' => 'Person'), 'value' => 'firm', 'optional' => true),
                array('name' => 'city', 'label' => 'City', 'type' => 'text', 'value' => $p['city'], 'optional' => true),
                array('name' => 'state', 'label' => 'State', 'type' => 'select', 'options' => $states, 'value' => $p['state'], 'optional' => true),
                array('name' => 'country', 'label' => 'Country, outside the US', 'type' => 'text', 'value' => $p['country'], 'optional' => true),
                array('name' => 'type', 'label' => 'Program type', 'type' => 'select', 'options' => $types, 'value' => '', 'optional' => true),
                array('name' => 'ptype', 'label' => 'Provider type', 'type' => 'text', 'value' => '', 'optional' => true, 'placeholder' => 'psychiatric hospital, outpatient...'),
                array('name' => 'force', 'label' => 'It is a different place from any close match', 'type' => 'checkbox', 'value' => '', 'optional' => true),
                array('name' => 'facilities', 'label' => 'Also file under these facilities', 'type' => 'facilities', 'value' => array(), 'optional' => true),
            ));
        }
        $actions[] = array('id' => 'skip', 'label' => 'Reject: skip these pages', 'style' => 'reject',
            'help' => 'Nothing is filed; the pages move to the Skipped tab.');
    } elseif ($filed) {
        $have = array_column($places, 'key');
        $fac_ids = array();
        foreach ($places as $p) if ($p['kind'] === 'facility' && $p['id']) $fac_ids[] = $p['id'];
        $parents = array_values(array_filter(kop_rinbox_wb_parents($fac_ids), function ($c) use ($have) { return !in_array('c' . $c['id'], $have, true); }));
        $add = kop_rinbox_wb_target_params($r, 0, array(), $parents, 'Also file under');
        $actions[] = array('id' => 'add', 'label' => 'Also file it there', 'style' => 'neutral', 'params' => $add,
            'help' => 'Lists the same filed PDF under another record as well; nothing is copied.');
        $extras = array();
        foreach ($places as $p) if (!$p['primary'] && $p['key'] !== '') $extras[$p['key']] = $p['name'] . ($p['kind'] === 'company' ? ' (company)' : '');
        if ($extras) {
            $actions[] = array('id' => 'untag', 'label' => 'Take it off', 'style' => 'neutral',
                'help' => 'Takes the PDF off the record you pick; it stays with the other records.',
                'params' => array(
                array('name' => 'record', 'label' => 'Take it off', 'type' => 'select', 'options' => $extras, 'value' => ''),
            ));
        }
        $actions[] = array('id' => 'undo', 'label' => 'Undo filing', 'style' => 'undo',
            'help' => 'Deletes the filed PDF from every record it is listed under and puts the pages back in the waiting list.',
            'confirm' => 'Delete the filed copy of these pages and put them back in the queue?');
    } else {
        $actions[] = array('id' => 'reopen', 'label' => 'Put back', 'style' => 'neutral',
            'help' => 'Nothing on the site changes; the pages wait for review again.');
    }

    $statuses = array('pending' => 'Waiting', 'filed' => 'Filed', 'skipped' => 'Skipped', 'gone' => 'No longer found');
    $title = $r['facility_name'] !== '' ? $r['facility_name'] : ($said !== '' ? $said : $issue);
    return array(
        'key'          => (string) $r['ckey'],
        'title'        => (string) $title,
        'subtitle'     => implode(' · ', array_filter(array($kinds[$r['kind']] ?? $r['kind'], $issue))),
        'url'          => '',
        'text'         => implode("\n", $lines),
        'created'      => (string) $r['created_at'],
        'status'       => (string) $r['status'],
        'status_label' => $statuses[$r['status']] ?? $r['status'],
        'facility'     => $r['target_kind'] === 'facility' || $r['target_kind'] === '' ? kop_rinbox_facility((int) $r['facility_id']) : null,
        'fields'       => array(
            array('name' => 'kind', 'label' => 'What it is', 'type' => 'select', 'options' => $kinds, 'value' => (string) $r['kind'], 'category' => true, 'readonly' => !$pending),
            array('name' => 'facility_id', 'label' => 'Best match', 'type' => 'facility', 'value' => (int) $r['facility_id'], 'readonly' => !$pending),
        ),
        'details'      => $details,
        'preview'      => $preview,
        'actions'      => $actions,
        'links'        => $links,
        // The old screen ticked articles with a confident match.
        'selected'     => $pending && $r['kind'] === 'section' && (int) $r['facility_id'] > 0,
    );
}

function kop_rinbox_wb_row($key) {
    $r = kop_wb_get(preg_replace('/[^a-f0-9]/', '', (string) $key));
    if (!$r) throw new RuntimeException('Those Woodbury pages are gone from the list.');
    return $r;
}

/** "Filed under A, B (company)" from kop_wb places. */
function kop_rinbox_wb_names(array $places) {
    $names = array();
    foreach ($places as $p) $names[] = $p['name'] . (($p['kind'] ?? '') === 'company' ? ' (company)' : '');
    return implode(', ', $names);
}

/** A 'record' lookup value ("consultant:12", "company:4", or "c4") as [kind, id]. */
function kop_rinbox_wb_record($value) {
    $value = strtolower(trim((string) $value));
    if (preg_match('/^c(\d+)$/', $value, $m)) return array('company', (int) $m[1]);
    if (preg_match('/^(company|consultant|provider|transporter):(\d+)$/', $value, $m)) return array($m[1], (int) $m[2]);
    throw new RuntimeException('Pick the record from the list that opens as you type its name.');
}

/** File under $target, then tag the pages into the extra facilities and companies, as kop_wbc_file_with_ticked() does. */
function kop_rinbox_wb_file_target(array $r, array $target, array $tokens, $user) {
    $filed = kop_wb_file_target($r, $target, $user);
    $msg = 'Filed under ' . $target['name'] . (in_array($target['kind'], array('facility', 'company'), true) ? '' : ' (' . strtolower(kop_wbc_kinds()[$target['kind']] ?? $target['kind']) . ')');
    if ($tokens) {
        try {
            $more = kop_wb_add_facilities(kop_wb_get($r['ckey']), $tokens);
            if ($more) $msg .= ', and ' . kop_rinbox_wb_names($more);
        } catch (Throwable $e) {
            $msg .= ', but not under the other records: ' . $e->getMessage();
        }
    }
    return $msg . '.';
}

function kop_rinbox_wb_act($key, $action, array $params) {
    global $wpdb;
    $r = kop_rinbox_wb_row($key);
    $user = kop_rinbox_reviewer();
    $waiting = function () use ($r) {
        if ($r['status'] !== 'pending') throw new RuntimeException('These pages were already handled (' . $r['status'] . ').');
    };
    switch ($action) {
        case 'file':
            $waiting();
            $tokens = kop_rinbox_wb_tokens($params);
            if (!$tokens && (int) $r['facility_id'] > 0 && !array_key_exists('facilities', $params)) $tokens[] = (string) (int) $r['facility_id'];
            if (!$tokens) throw new RuntimeException('Pick the facility to file the pages under.');
            $res = kop_wb_file_many($r, $tokens, $user);
            $names = kop_rinbox_wb_names((array) ($res['places'] ?? array()));
            return array('message' => 'Filed' . ($names !== '' ? ' under ' . $names : '') . ': the pages show in the "Woodbury Reports Mentions" folder on the page. Undo is on the Filed tab.');
        case 'file_record':
            $waiting();
            if (!function_exists('kop_wbc_existing_target')) throw new RuntimeException('Filing under other records is not available.');
            list($kind, $id) = kop_rinbox_wb_record($params['record'] ?? '');
            $target = kop_wbc_existing_target($kind, $kind === 'company' ? kop_wb_company_lead($id) : $id);
            return array('message' => kop_rinbox_wb_file_target($r, $target, kop_rinbox_wb_tokens(array('facilities' => $params['facilities'] ?? array())), $user)
                . ' Undo is on the Filed tab.');
        case 'create':
            $waiting();
            if (!function_exists('kop_wbc_create')) throw new RuntimeException('Record creation is not available.');
            $f = array();
            foreach (array('kind', 'name', 'city', 'state', 'country', 'type', 'who') as $k) {
                $f[$k] = sanitize_text_field((string) ($params[$k] ?? ''));
            }
            if (($f['kind'] ?? '') === 'provider') $f['type'] = sanitize_text_field((string) ($params['ptype'] ?? ''));
            $f['force'] = !empty($params['force']) && $params['force'] !== '0' ? '1' : '';
            // One create per candidate at a time, as the old screen's kop_wb_create.
            $lock = 'kop_wbc_' . $r['ckey'];
            if (!(int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
                throw new RuntimeException('Another request is creating this one. Try again in a minute.');
            }
            try {
                $target = kop_wbc_create($r, $f);
            } finally {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
            $label = strtolower(kop_wbc_kinds()[$target['kind']] ?? $target['kind']);
            try {
                $msg = kop_rinbox_wb_file_target($r, $target, kop_rinbox_wb_tokens(array('facilities' => $params['facilities'] ?? array())), $user);
            } catch (Throwable $e) {
                throw new RuntimeException('Created the ' . $label . ' record "' . $target['name'] . '" (#' . (int) $target['id'] . '), but filing the pages failed: ' . $e->getMessage());
            }
            return array('message' => 'Created the ' . $label . ' record "' . $target['name'] . '" (#' . (int) $target['id'] . '). ' . $msg
                . ' Undo on the Filed tab takes the pages back out; the record stays.');
        case 'add':
            if ($r['status'] !== 'filed') throw new RuntimeException('File it first.');
            $tokens = kop_rinbox_wb_tokens($params);
            if (!$tokens) throw new RuntimeException('Pick a facility or company to file it under too.');
            $more = kop_wb_add_facilities($r, $tokens);
            if (!$more) throw new RuntimeException('Already filed there.');
            return array('message' => 'Also filed under ' . kop_rinbox_wb_names($more) . '.');
        case 'untag':
            if ($r['status'] !== 'filed') throw new RuntimeException('Only filed pages can be taken off a record.');
            $gone = kop_wb_remove_facility($r, kop_wb_clean_token($params['record'] ?? ''));
            return array('message' => 'Taken off ' . $gone['name'] . '. The PDF stays with the other records.');
        case 'skip':
            $waiting();
            kop_wb_set_status($r, 'skipped', $user);
            return array('message' => 'Skipped. Nothing was filed; "Put back" on the Skipped tab brings it back.');
        case 'reopen':
            if (!in_array($r['status'], array('skipped', 'gone'), true)) throw new RuntimeException('Only skipped pages can be put back.');
            kop_wb_set_status($r, 'pending', $user);
            return array('message' => 'Waiting for review again.');
        case 'undo':
            if ($r['status'] !== 'filed') throw new RuntimeException('Only filed pages can be undone.');
            kop_wb_undo($r, $user);
            return array('message' => 'Undone: the filed copy is deleted and the pages are waiting for review again.');
    }
    throw new RuntimeException('Unknown action.');
}

/** Companies (value c<id>) or any non-facility record (value kind:id) by name, as the old screen's searches. */
function kop_rinbox_wb_lookup($name, $q) {
    if (!function_exists('kop_wbc_find_records')) return array();
    $out = array();
    $only = $name === 'company' ? 'company' : '';
    foreach (kop_wbc_find_records(kop_wbc_pdo(), $q, 15, $only) as $h) {
        $value = $name === 'company' ? 'c' . (int) $h['id'] : $h['kind'] . ':' . (int) $h['id'];
        $out[] = array('value' => $value, 'label' => $h['name'] . ' · ' . $h['label'] . ($h['detail'] !== '' ? ' · ' . $h['detail'] : ''));
    }
    return $out;
}

function kop_rinbox_wb_tool($id, array $params) {
    if ($id === 'resync') {
        $sync = kop_wb_sync(true);
        delete_transient('kop_rinbox_wb_synced');
        if (!$sync) return array('message' => is_readable(kop_wb_pending_dir() . '/candidates.json') ? 'The scan could not be read.' : 'No scan is uploaded yet.');
        return array('message' => 'Loaded the scan: ' . (int) $sync['added'] . ' new, ' . (int) $sync['updated'] . ' updated, ' . (int) $sync['gone'] . ' no longer found.');
    }
    if ($id === 'dedupe') {
        // As admin_post_kop_wb_dedupe: the extras go, the copy the candidate points at stays.
        global $wpdb;
        $removed = 0;
        foreach (kop_wb_duplicate_copies() as $dup) {
            foreach ($dup['extras'] as $att) {
                $wpdb->delete($wpdb->prefix . 'fbv_attachment_folder', array('attachment_id' => $att), array('%d'));
                if (wp_delete_attachment($att, true)) $removed++;
            }
        }
        delete_transient('kop_hidden_preview_ids');
        return array('message' => $removed ? 'Removed ' . $removed . ' extra ' . ($removed === 1 ? 'copy' : 'copies') . '.' : 'No extra copies: every filed report has one copy.');
    }
    throw new RuntimeException('Unknown tool.');
}

function kop_rinbox_wb_save($key, array $fields) {
    global $wpdb;
    $r = kop_rinbox_wb_row($key);
    if ($r['status'] !== 'pending') throw new RuntimeException('Undo the filing (or put it back) before editing.');
    $set = array();
    if (isset($fields['kind']) && $fields['kind'] !== '') {
        if (!isset(kop_rinbox_wb_kinds()[$fields['kind']])) throw new RuntimeException('Pick what the pages are from the list.');
        $set['kind'] = (string) $fields['kind'];
    }
    if (array_key_exists('facility_id', $fields) && (int) $fields['facility_id'] !== (int) $r['facility_id']) {
        $fid = max(0, (int) $fields['facility_id']);
        $name = '';
        $state = '';
        if ($fid > 0) {
            $st = kop_rinbox_pdo()->prepare('SELECT name, state FROM facilities_v2 WHERE id = ?');
            $st->execute(array($fid));
            $f = $st->fetch(PDO::FETCH_ASSOC);
            if (!$f) throw new RuntimeException('Facility #' . $fid . ' does not exist.');
            $name = (string) $f['name'];
            $state = (string) $f['state'];
        }
        $set['facility_id'] = $fid;
        $set['facility_name'] = mb_substr($name, 0, 255);
        $set['facility_state'] = mb_substr($state, 0, 8);
    }
    if (!$set) return array('message' => 'Nothing to save.');
    $wpdb->update(kop_wb_table(), $set, array('ckey' => $r['ckey']));
    kop_rinbox_flush_counts();
    return array('message' => 'Saved.');
}
