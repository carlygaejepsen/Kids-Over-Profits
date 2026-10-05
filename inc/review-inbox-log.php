<?php
/**
 * Review inbox: what was done (with Undo), what is set aside, and link previews.
 *
 * Recently done: every action taken in the inbox (and approve / reject /
 * publish / PR on the page's own five types, from api/manage-submissions.php)
 * is a row in {prefix}kop_review_log with the item's title, who did it and
 * the action that takes it back: for a queue item, the 'undo' style action
 * the item offers right after (kop_rinbox_log_action()); for the five types,
 * 'restore' to the status it had (kop_rinbox_log_native()). Undo from the
 * log runs that action through the queue's own 'act', once.
 *
 * Set aside: {prefix}kop_review_holds holds an item snoozed until a date
 * and/or handed to another admin. Until then it leaves the waiting list of
 * everyone it is not assigned to (kop_rinbox_hidden_keys()); acting on it
 * (approve / reject) clears the hold. The assignee gets an email with the
 * link. "Assigned to you" and "Snoozed" list them across every queue.
 *
 * Preview: kop/v1/review-inbox/preview?url= says whether the page can be shown
 * in a frame (our own site, Google Drive and Docs links as /preview, sites
 * without X-Frame-Options / frame-ancestors) and gives a reading copy
 * (title, site, picture, the text through kop_enrich_document_text(), which
 * reads PDFs and falls back to the Wayback copy), cached 12 hours.
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_rinbox_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_review_log';
}

function kop_rinbox_holds_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_review_holds';
}

/** Created with the tags table by kop_rinbox_ensure_tables() (DB version 2). */
function kop_rinbox_log_tables_sql($collate) {
    $log = kop_rinbox_log_table();
    $holds = kop_rinbox_holds_table();
    return array(
        "CREATE TABLE $log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source VARCHAR(40) NOT NULL,
            item_key VARCHAR(191) NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            action VARCHAR(40) NOT NULL,
            action_label VARCHAR(191) NOT NULL DEFAULT '',
            style VARCHAR(12) NOT NULL DEFAULT '',
            message TEXT NULL,
            undo_action VARCHAR(40) NOT NULL DEFAULT '',
            undo_params TEXT NULL,
            user_login VARCHAR(60) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            undone_at DATETIME NULL,
            undone_by VARCHAR(60) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY item (source, item_key)
        ) $collate;",
        "CREATE TABLE $holds (
            source VARCHAR(40) NOT NULL,
            item_key VARCHAR(191) NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            assigned_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
            snooze_until DATETIME NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_by VARCHAR(60) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (source, item_key),
            KEY assigned_to (assigned_to)
        ) $collate;",
    );
}

/* ---- Recently done -------------------------------------------------------- */

function kop_rinbox_log_insert(array $row) {
    global $wpdb;
    kop_rinbox_ensure_tables();
    $wpdb->insert(kop_rinbox_log_table(), array(
        'source'       => (string) $row['source'],
        'item_key'     => (string) $row['item_key'],
        'title'        => mb_substr((string) ($row['title'] ?? ''), 0, 255),
        'action'       => mb_substr((string) $row['action'], 0, 40),
        'action_label' => mb_substr((string) ($row['action_label'] ?? ''), 0, 191),
        'style'        => (string) ($row['style'] ?? ''),
        'message'      => (string) ($row['message'] ?? ''),
        'undo_action'  => (string) ($row['undo_action'] ?? ''),
        'undo_params'  => !empty($row['undo_params']) ? wp_json_encode($row['undo_params']) : null,
        'user_login'   => kop_rinbox_reviewer(),
        'created_at'   => current_time('mysql', true),
    ));
    // An approve or reject closes what volunteers recommended on the item, with this outcome.
    $style = (string) ($row['style'] ?? '');
    if (($style === 'approve' || $style === 'reject') && function_exists('kop_vol_resolve')) {
        kop_vol_resolve((string) $row['source'], (string) $row['item_key'], $style, kop_rinbox_reviewer());
    }
}

/**
 * Record an inbox action. $before is the item as it was (null: unknown), $after
 * as it is now (null: it left the queue). The Undo is the first 'undo' style
 * action $after offers that needs no answer.
 */
function kop_rinbox_log_action($source, $key, $action, array $params, $before, $after, $message, $undo_as = null) {
    try {
        if (in_array($action, array('save'), true)) return;
        $label = $action;
        $style = '';
        foreach ((array) ($before['actions'] ?? array()) as $a) {
            if (($a['id'] ?? '') === $action) { $label = (string) $a['label']; $style = (string) ($a['style'] ?? ''); break; }
        }
        if ($action === 'move') {
            $style = 'neutral';
            foreach ((array) ($before['moves'] ?? array()) as $m) {
                if (($m['id'] ?? '') === ($params['to'] ?? '')) { $label = 'Moved to ' . $m['label']; break; }
            }
        }
        $undo = '';
        $undo_params = null;
        if (is_array($undo_as) && !empty($undo_as['action'])) {
            // The act said how to take it back (kop_rinbox_gdl_group_act: every link one click added).
            $undo = (string) $undo_as['action'];
            $undo_params = (array) ($undo_as['params'] ?? array());
        } elseif ($style !== 'undo' && is_array($after)) {
            foreach ((array) ($after['actions'] ?? array()) as $a) {
                if (($a['style'] ?? '') !== 'undo') continue;
                $required = array_filter((array) ($a['params'] ?? array()), function ($p) { return empty($p['optional']) && (string) ($p['value'] ?? '') === ''; });
                if (!$required) { $undo = (string) $a['id']; break; }
            }
        }
        kop_rinbox_log_insert(array(
            'source' => $source, 'item_key' => $key,
            'title' => (string) (($before['title'] ?? '') ?: ($after['title'] ?? '') ?: $key),
            'action' => $action, 'action_label' => $label, 'style' => $style, 'message' => (string) $message,
            'undo_action' => $undo, 'undo_params' => $undo_params,
        ));
        if ($style === 'approve' || $style === 'reject' || $after === null) kop_rinbox_hold_clear($source, $key);
    } catch (Throwable $e) {
        // The log never stops the action itself.
    }
}

/**
 * Approve / reject / publish / PR on the page's own five types, from
 * api/manage-submissions.php. $before: [id => [status, title]] read before
 * the change. Approving a data edit writes it into the facility record, which
 * a status change cannot take back, so it gets no Undo.
 */
function kop_rinbox_log_native($type, array $before, $action) {
    try {
        $labels = array('approve' => 'Approve', 'reject' => 'Reject', 'publish' => 'Publish', 'promo' => 'File as industry PR');
        $styles = array('approve' => 'approve', 'reject' => 'reject', 'publish' => 'approve', 'promo' => 'reject');
        foreach ($before as $id => $b) {
            $undo = !($type === 'data' && $action !== 'reject');
            kop_rinbox_log_insert(array(
                'source' => $type, 'item_key' => (string) (int) $id, 'title' => (string) ($b['title'] ?? ''),
                'action' => $action, 'action_label' => $labels[$action] ?? $action, 'style' => $styles[$action] ?? '',
                'message' => '', 'undo_action' => $undo ? 'restore' : '',
                'undo_params' => $undo ? array('status' => (string) $b['status']) : null,
            ));
            kop_rinbox_hold_clear($type, (string) (int) $id);
        }
    } catch (Throwable $e) {
        // Never in the way of the review itself.
    }
}

function kop_rinbox_log_list(array $q) {
    global $wpdb;
    kop_rinbox_ensure_tables();
    $t = kop_rinbox_log_table();
    $where = array('1=1');
    $args = array();
    if (!empty($q['mine'])) { $where[] = 'user_login = %s'; $args[] = kop_rinbox_reviewer(); }
    if (!empty($q['source'])) { $where[] = 'source = %s'; $args[] = (string) $q['source']; }
    if (($q['search'] ?? '') !== '') { $where[] = 'title LIKE %s'; $args[] = '%' . $wpdb->esc_like((string) $q['search']) . '%'; }
    $sqlWhere = implode(' AND ', $where);
    $total = (int) $wpdb->get_var($args ? $wpdb->prepare("SELECT COUNT(*) FROM $t WHERE $sqlWhere", $args) : "SELECT COUNT(*) FROM $t WHERE $sqlWhere");
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t WHERE $sqlWhere ORDER BY id DESC LIMIT %d OFFSET %d",
        array_merge($args, array((int) $q['limit'], (int) $q['offset']))
    ), ARRAY_A);
    $sources = kop_rinbox_sources();
    $out = array();
    foreach ((array) $rows as $r) {
        $out[] = array(
            'id' => (int) $r['id'], 'source' => $r['source'], 'source_label' => $sources[$r['source']]['label'] ?? $r['source'],
            'key' => $r['item_key'], 'title' => $r['title'], 'action' => $r['action'], 'action_label' => $r['action_label'],
            'style' => $r['style'], 'message' => (string) $r['message'], 'user' => $r['user_login'],
            'created' => $r['created_at'] . 'Z', 'can_undo' => $r['undo_action'] !== '' && empty($r['undone_at']),
            'undone' => !empty($r['undone_at']) ? array('at' => $r['undone_at'] . 'Z', 'by' => $r['undone_by']) : null,
        );
    }
    return array('rows' => $out, 'total' => $total);
}

/** Take one logged action back through its queue's own Undo. */
function kop_rinbox_log_undo($id) {
    global $wpdb;
    kop_rinbox_ensure_tables();
    $t = kop_rinbox_log_table();
    $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id = %d", (int) $id), ARRAY_A);
    if (!$r) throw new RuntimeException('That entry is gone.');
    if (!empty($r['undone_at'])) throw new RuntimeException('Already undone by ' . $r['undone_by'] . '.');
    if ($r['undo_action'] === '') throw new RuntimeException('This one has no Undo.');
    // Claim it first so two clicks never run the Undo twice.
    // A one-time token read back: only the request whose token stuck runs it.
    $token = substr(md5(uniqid('', true)), 0, 12);
    $wpdb->query($wpdb->prepare(
        "UPDATE $t SET undone_at = %s, undone_by = %s WHERE id = %d AND undone_at IS NULL",
        current_time('mysql', true), '#' . $token, (int) $id
    ));
    if ((string) $wpdb->get_var($wpdb->prepare("SELECT undone_by FROM $t WHERE id = %d", (int) $id)) !== '#' . $token) {
        throw new RuntimeException('Already undone.');
    }
    $wpdb->query($wpdb->prepare("UPDATE $t SET undone_by = %s WHERE id = %d", kop_rinbox_reviewer(), (int) $id));
    $src = kop_rinbox_source($r['source']);
    $params = json_decode((string) $r['undo_params'], true);
    try {
        $res = call_user_func($src['act'], (string) $r['item_key'], $r['undo_action'], is_array($params) ? $params : array());
    } catch (Throwable $e) {
        $wpdb->query($wpdb->prepare("UPDATE $t SET undone_at = NULL, undone_by = '' WHERE id = %d", (int) $id));
        throw $e;
    }
    kop_rinbox_flush_counts();
    if (in_array($r['style'], array('approve', 'reject'), true) && function_exists('kop_vol_reopen')) kop_vol_reopen($r['source'], $r['item_key']);
    $res = is_array($res) ? $res : array();
    return array('message' => ($res['message'] ?? '') !== '' ? $res['message'] : 'Undone.');
}

/* ---- Set aside (snooze / assign) ------------------------------------------ */

/** Admins an item can be handed to: [{id, name}]. */
function kop_rinbox_admins() {
    $out = array();
    foreach (get_users(array('capability' => 'manage_options', 'fields' => array('ID', 'display_name', 'user_login'), 'orderby' => 'display_name')) as $u) {
        $out[] = array('id' => (int) $u->ID, 'name' => (string) ($u->display_name ?: $u->user_login));
    }
    return $out;
}

function kop_rinbox_user_name($id) {
    $u = $id ? get_userdata((int) $id) : null;
    return $u ? (string) ($u->display_name ?: $u->user_login) : '';
}

/** Holds still in force: snoozed into the future, or assigned to someone. Read once a request ($reset reads again). */
function kop_rinbox_holds_active($source = null, $reset = false) {
    global $wpdb;
    static $all = null;
    if ($all === null || $reset) {
        $all = array();
        try {
            kop_rinbox_ensure_tables();
            $t = kop_rinbox_holds_table();
            $now = current_time('mysql', true);
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE assigned_to > 0 OR snooze_until > %s", $now), ARRAY_A);
            foreach ((array) $rows as $r) {
                if (!empty($r['snooze_until']) && $r['snooze_until'] <= $now) $r['snooze_until'] = null;
                $all[$r['source']][$r['item_key']] = $r;
            }
        } catch (Throwable $e) {
            $all = array();
        }
    }
    if ($source === null) return $all;
    return $all[$source] ?? array();
}

/** Keys of $source that leave my waiting list: snoozed, or someone else's. */
function kop_rinbox_hidden_keys($source) {
    $me = get_current_user_id();
    $out = array();
    foreach (kop_rinbox_holds_active($source) as $key => $h) {
        if (!empty($h['snooze_until']) || ((int) $h['assigned_to'] > 0 && (int) $h['assigned_to'] !== $me)) $out[(string) $key] = true;
    }
    return $out;
}

/** What the card shows about a hold, or null. */
function kop_rinbox_hold_info($source, $key) {
    $h = kop_rinbox_holds_active($source)[(string) $key] ?? null;
    if (!$h) return null;
    $me = get_current_user_id();
    return array(
        'assigned_to'   => (int) $h['assigned_to'],
        'assigned_name' => kop_rinbox_user_name($h['assigned_to']),
        'mine'          => (int) $h['assigned_to'] === $me,
        'snooze_until'  => !empty($h['snooze_until']) ? $h['snooze_until'] . 'Z' : '',
        'note'          => (string) $h['note'],
        'by'            => (string) $h['created_by'],
        'hidden'        => !empty($h['snooze_until']) || ((int) $h['assigned_to'] > 0 && (int) $h['assigned_to'] !== $me),
    );
}

/**
 * Snooze ($days > 0) and/or hand to $assign (user id; 0 = nobody). An empty
 * hold (no snooze, nobody) is removed.
 */
function kop_rinbox_hold_set($source, $key, $title, $days, $assign, $note) {
    global $wpdb;
    kop_rinbox_source($source);
    kop_rinbox_ensure_tables();
    $assign = (int) $assign;
    if ($assign > 0 && !user_can($assign, 'manage_options')) throw new RuntimeException('That person cannot review.');
    $days = max(0, min(365, (int) $days));
    $until = $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS) : null;
    $t = kop_rinbox_holds_table();
    if (!$until && !$assign) {
        kop_rinbox_hold_clear($source, $key);
        return array('message' => 'Back in the waiting list.');
    }
    $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE source = %s AND item_key = %s", $source, (string) $key), ARRAY_A);
    $wpdb->delete($t, array('source' => $source, 'item_key' => (string) $key));
    $wpdb->insert($t, array(
        'source' => $source, 'item_key' => (string) $key, 'title' => mb_substr((string) $title, 0, 255),
        'assigned_to' => $assign, 'snooze_until' => $until, 'note' => mb_substr((string) $note, 0, 255),
        'created_by' => kop_rinbox_reviewer(), 'created_at' => current_time('mysql', true),
    ));
    $bits = array();
    if ($assign) {
        $bits[] = 'Handed to ' . kop_rinbox_user_name($assign);
        if ($assign !== get_current_user_id() && (int) ($old['assigned_to'] ?? 0) !== $assign) kop_rinbox_hold_mail($assign, $source, $title, $note);
    }
    if ($until) $bits[] = ($bits ? 'back' : 'Snoozed: back') . ' on ' . wp_date('M j', time() + $days * DAY_IN_SECONDS);
    return array('message' => implode(', ', $bits) . '.');
}

function kop_rinbox_hold_clear($source, $key) {
    global $wpdb;
    kop_rinbox_ensure_tables();
    $wpdb->delete(kop_rinbox_holds_table(), array('source' => (string) $source, 'item_key' => (string) $key));
}

function kop_rinbox_hold_mail($user_id, $source, $title, $note) {
    $u = get_userdata((int) $user_id);
    if (!$u || !$u->user_email) return;
    $src = kop_rinbox_sources()[$source] ?? array('label' => $source);
    $url = function_exists('kop_find_template_page_url') ? (string) kop_find_template_page_url('page-admin-submissions.php') : home_url('/admin-submissions/');
    $url = add_query_arg('type', '_mine', $url);
    $body = kop_rinbox_reviewer() . ' handed you an item in the Review Inbox (' . $src['label'] . "):\n\n"
        . $title . "\n" . ($note !== '' ? "\nNote: " . $note . "\n" : '') . "\nEverything assigned to you: " . $url . "\n";
    wp_mail($u->user_email, 'Review Inbox: ' . mb_substr((string) $title, 0, 80), $body);
}

/** Held items across every queue: 'mine' (assigned to me) or 'snoozed'. Cards, newest hold first. */
function kop_rinbox_held_items($which) {
    $me = get_current_user_id();
    $rows = array();
    foreach (kop_rinbox_holds_active() as $byKey) {
        foreach ($byKey as $h) {
            $mine = (int) $h['assigned_to'] === $me;
            if ($which === 'mine' ? !$mine : empty($h['snooze_until'])) continue;
            $rows[] = $h;
        }
    }
    usort($rows, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    $items = array();
    $sources = kop_rinbox_sources();
    foreach (array_slice($rows, 0, 100) as $h) {
        if (!isset($sources[$h['source']])) continue;
        try {
            $item = kop_rinbox_get_item($h['source'], $h['item_key']);
        } catch (Throwable $e) {
            // Handled elsewhere since: the hold has nothing left to hold.
            kop_rinbox_hold_clear($h['source'], $h['item_key']);
            continue;
        }
        $item['source'] = $h['source'];
        $item['source_label'] = $sources[$h['source']]['label'];
        $item['native'] = !empty($sources[$h['source']]['native']);
        $items[] = $item;
    }
    return array('items' => $items, 'total' => count($items));
}

/** How many are assigned to me and how many are snoozed (any queue). */
function kop_rinbox_held_counts() {
    $me = get_current_user_id();
    $mine = 0;
    $snoozed = 0;
    foreach (kop_rinbox_holds_active() as $byKey) {
        foreach ($byKey as $h) {
            if ((int) $h['assigned_to'] === $me) $mine++;
            if (!empty($h['snooze_until'])) $snoozed++;
        }
    }
    return array('mine' => $mine, 'snoozed' => $snoozed);
}

/**
 * One page of the waiting view without the held items. The queue's own list
 * is read in steps from $q['offset'] until $q['limit'] items are left;
 * 'next_offset' is where the next page starts in the queue's own order.
 */
function kop_rinbox_list_unheld(array $src, $source, array $q) {
    $hidden = kop_rinbox_hidden_keys($source);
    if (!$hidden) {
        $res = call_user_func($src['list'], $q);
        $res['next_offset'] = $q['offset'] + count((array) ($res['items'] ?? array()));
        return $res;
    }
    $items = array();
    $offset = $q['offset'];
    $total = 0;
    for ($i = 0; $i < 20 && count($items) < $q['limit']; $i++) {
        $res = call_user_func($src['list'], array('offset' => $offset) + $q);
        $page = (array) ($res['items'] ?? array());
        $total = (int) ($res['total'] ?? 0);
        foreach ($page as $it) {
            $offset++;
            if (isset($hidden[(string) $it['key']])) continue;
            $items[] = $it;
            if (count($items) >= $q['limit']) break;
        }
        if (count($page) < $q['limit'] || $offset >= $total) break;
    }
    return array('items' => $items, 'total' => max(0, $total - count($hidden)), 'next_offset' => $offset, 'held' => count($hidden));
}

/* ---- Preview -------------------------------------------------------------- */

/** A Google Drive / Docs link as the address that shows it in a frame, or ''. */
function kop_rinbox_google_preview_url($url) {
    if (preg_match('#^https://drive\.google\.com/file/d/([A-Za-z0-9_-]+)#', $url, $m)) return 'https://drive.google.com/file/d/' . $m[1] . '/preview';
    if (preg_match('#^https://drive\.google\.com/(?:open|uc)\?(?:.*&)?id=([A-Za-z0-9_-]+)#', $url, $m)) return 'https://drive.google.com/file/d/' . $m[1] . '/preview';
    if (preg_match('#^https://docs\.google\.com/(document|spreadsheets|presentation)/d/([A-Za-z0-9_-]+)#', $url, $m)) return 'https://docs.google.com/' . $m[1] . '/d/' . $m[2] . '/preview';
    return '';
}

function kop_rinbox_preview($url) {
    $url = trim((string) $url);
    if (!preg_match('#^https?://#i', $url) || !wp_http_validate_url($url)) throw new RuntimeException('Not a web address.');
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $home = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    $out = array('url' => $url, 'host' => preg_replace('/^www\./', '', $host), 'frame_url' => '', 'kind' => 'page',
        'title' => '', 'site' => '', 'image' => '', 'text' => '', 'note' => '');
    if ($host === $home || $host === 'www.' . $home) {
        $out['frame_url'] = $url;
        $out['kind'] = preg_match('/\.pdf(\?|$)/i', $url) ? 'pdf' : 'page';
        return $out;
    }
    if ($g = kop_rinbox_google_preview_url($url)) {
        $out['frame_url'] = $g;
        $out['kind'] = 'drive';
        return $out;
    }
    $cache = 'kop_rinbox_pv_' . md5($url);
    $hit = get_transient($cache);
    if (is_array($hit)) return $hit;

    $res = wp_remote_get($url, array('timeout' => 20, 'redirection' => 5, 'limit_response_size' => 6 * MB_IN_BYTES,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'));
    $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
    $type = is_wp_error($res) ? '' : strtolower((string) wp_remote_retrieve_header($res, 'content-type'));
    $body = is_wp_error($res) ? '' : (string) wp_remote_retrieve_body($res);
    $isPdf = strpos($type, 'pdf') !== false || strncmp($body, '%PDF', 4) === 0;
    $out['kind'] = $isPdf ? 'pdf' : 'page';

    // A frame works when the site allows it and the address is https (an http page is blocked inside https).
    $xfo = is_wp_error($res) ? '' : strtolower((string) wp_remote_retrieve_header($res, 'x-frame-options'));
    $csp = is_wp_error($res) ? '' : strtolower(implode(';', (array) wp_remote_retrieve_header($res, 'content-security-policy')));
    $framed = $code >= 200 && $code < 400 && stripos($url, 'https://') === 0 && $xfo === '';
    if ($framed && preg_match('/frame-ancestors\s+([^;]*)/', $csp, $m)) {
        $framed = strpos($m[1], '*') !== false || strpos($m[1], $home) !== false;
    }
    if ($framed) $out['frame_url'] = $url;

    if (!$isPdf && $body !== '') {
        $meta = function ($names) use ($body) {
            foreach ((array) $names as $n) {
                if (preg_match('#<meta[^>]+(?:property|name)=["\']' . preg_quote($n, '#') . '["\'][^>]*content=["\']([^"\']*)#i', $body, $m)
                    || preg_match('#<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']' . preg_quote($n, '#') . '["\']#i', $body, $m)) {
                    $v = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($v !== '') return $v;
                }
            }
            return '';
        };
        $out['title'] = $meta(array('og:title', 'twitter:title'));
        if ($out['title'] === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) {
            $out['title'] = trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $out['site'] = $meta('og:site_name');
        $img = $meta(array('og:image', 'twitter:image'));
        if ($img !== '' && preg_match('#^https://#i', $img)) $out['image'] = $img;
        $desc = $meta(array('og:description', 'description'));
    }
    $text = '';
    if (kop_rinbox_load_enrich()) {
        try {
            $text = (string) kop_enrich_document_text($url);
        } catch (Throwable $e) {
            $text = '';
        }
    }
    if (trim($text) === '' && !empty($desc)) $text = $desc;
    $text = trim(preg_replace("/[ \t]+/u", ' ', $text));
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    if (mb_strlen($text) > 8000) $text = mb_substr($text, 0, 8000) . '…';
    $out['text'] = $text;
    if ($code >= 400 || $code === 0) {
        $out['note'] = 'The site would not show this page to our server' . ($code ? ' (error ' . $code . ')' : '') . ($text !== '' ? '; the text below is from the Wayback Machine or the article reader.' : '.');
    } elseif ($text === '' && !$framed) {
        $out['note'] = 'No readable text on this page, and the site does not allow it to be shown here.';
    }
    set_transient($cache, $out, 12 * HOUR_IN_SECONDS);
    return $out;
}

/* ---- REST ----------------------------------------------------------------- */

add_action('rest_api_init', function () {
    $perm = function () { return current_user_can('manage_options'); };
    $routes = array(
        'log'     => array('GET', 'kop_rinbox_rest_log'),
        'undo'    => array('POST', 'kop_rinbox_rest_undo'),
        'hold'    => array('POST', 'kop_rinbox_rest_hold'),
        'held'    => array('GET', 'kop_rinbox_rest_held'),
        'preview' => array('GET', 'kop_rinbox_rest_preview'),
    );
    foreach ($routes as $path => $r) {
        register_rest_route('kop/v1', '/review-inbox/' . $path, array(
            'methods' => $r[0], 'callback' => $r[1], 'permission_callback' => $perm,
        ));
    }
});

function kop_rinbox_rest_log(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        return kop_rinbox_log_list(array(
            'mine' => (bool) $req->get_param('mine'), 'source' => sanitize_key((string) $req->get_param('source')),
            'search' => trim((string) $req->get_param('search')),
            'offset' => max(0, (int) $req->get_param('offset')), 'limit' => max(1, min(100, (int) ($req->get_param('limit') ?: 50))),
        ));
    });
}

function kop_rinbox_rest_undo(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        return kop_rinbox_log_undo((int) $req->get_param('id'));
    });
}

function kop_rinbox_rest_hold(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $source = (string) $req->get_param('source');
        $key = (string) $req->get_param('key');
        $title = (string) $req->get_param('title');
        if ($title === '') {
            try { $title = (string) kop_rinbox_get_item($source, $key)['title']; } catch (Throwable $e) { $title = $key; }
        }
        $res = kop_rinbox_hold_set($source, $key, wp_unslash($title), (int) $req->get_param('days'), (int) $req->get_param('assign'), wp_unslash((string) $req->get_param('note')));
        kop_rinbox_log_insert(array('source' => $source, 'item_key' => $key, 'title' => wp_unslash($title), 'action' => 'hold',
            'action_label' => 'Set aside', 'style' => 'neutral', 'message' => $res['message']));
        return $res;
    });
}

function kop_rinbox_rest_held(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $which = (string) $req->get_param('which');
        if ($which === 'volunteers' && function_exists('kop_vol_admin_items')) return kop_vol_admin_items();
        return kop_rinbox_held_items($which === 'snoozed' ? 'snoozed' : 'mine');
    });
}

function kop_rinbox_rest_preview(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        return kop_rinbox_preview((string) $req->get_param('url'));
    });
}
