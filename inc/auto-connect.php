<?php
/**
 * Imports connect themselves when the name is exact (owner, 2026-10-12): a
 * name found in an import's text that is exactly a name of one record (same
 * name key: case, punctuation, "&"/"and", a leading "The" do not count) is
 * connected to that record without waiting for a click. Anything else, a close
 * name, a name two records share, a board's or a website's program, a header
 * whose state disagrees, stays in its queue for approval.
 *
 * Queues that are only about the connection:
 *   - Woodbury Reports pages: article headers, news items and mentions whose
 *     name is exactly one record's (kop_wb_file_many()).
 *   - Wiki links: an entry with exactly one record of its exact name
 *     (kop_wiki_upd_link(), as 'suggested').
 *   - Fornits discussion links: the program named in the posts' text, no other
 *     record of that name (kop_fornits_apply(), the "Survivor posts and
 *     discussion" link); board-only and title-only matches wait.
 *   - State Lists "Already on file": the listed name is now exactly one record's
 *     name in that state (kop_sl_act('link'), no name added).
 * Drive Docs is not here: its Add publishes the link (news approved, cases
 * published, links on the page), which is more than a connection.
 *
 * Each item is done once: {prefix}kop_auto_connect remembers it, so one a
 * person undoes is never connected again by itself. Each appears in its own
 * queue's done tab (by 'auto') and in Review inbox > Recently done, with Undo.
 * Runs hourly, a bounded time per run.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_AUTO_CONNECT_DB_VERSION', '1');

function kop_autoconn_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_auto_connect';
}

function kop_autoconn_ensure_table() {
    if (get_option('kop_auto_connect_db') === KOP_AUTO_CONNECT_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . kop_autoconn_table() . " (
        source VARCHAR(32) NOT NULL,
        item_key VARCHAR(64) NOT NULL,
        target VARCHAR(255) NOT NULL DEFAULT '',
        outcome VARCHAR(12) NOT NULL DEFAULT 'done',
        note TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (source, item_key)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('kop_auto_connect_db', KOP_AUTO_CONNECT_DB_VERSION);
}

/** item keys of $source already tried (connected, or failed and left for a person). */
function kop_autoconn_tried($source) {
    global $wpdb;
    return array_flip((array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_autoconn_table() . ' WHERE source = %s', $source)));
}

function kop_autoconn_mark($source, $key, $target, $outcome, $note = '') {
    global $wpdb;
    $wpdb->delete(kop_autoconn_table(), array('source' => $source, 'item_key' => (string) $key));
    $wpdb->insert(kop_autoconn_table(), array(
        'source' => $source, 'item_key' => (string) $key, 'target' => mb_substr((string) $target, 0, 255),
        'outcome' => $outcome, 'note' => (string) $note, 'created_at' => current_time('mysql', true),
    ));
}

/** A line in Review inbox > Recently done, by 'auto', with the queue's own Undo. */
function kop_autoconn_log($source, $key, $title, $message, $undo_action = 'undo') {
    if (!function_exists('kop_rinbox_log_insert')) return;
    try {
        kop_rinbox_log_insert(array(
            'source' => $source, 'item_key' => (string) $key, 'title' => (string) $title,
            'action' => 'auto', 'action_label' => 'Connected automatically (exact name)', 'style' => 'approve',
            'message' => (string) $message, 'undo_action' => (string) $undo_action, 'user_login' => 'auto',
        ));
    } catch (Throwable $e) {
        // The log never stops the connection.
    }
}

if (!function_exists('kop_wiki_upd_tokens') && is_file(__DIR__ . '/wiki-updates.php')) {
    require_once __DIR__ . '/wiki-updates.php';
}

/** A name as scripts/woodbury-scan.py key() makes it: accents, case, "&", "'s", punctuation, "The", "Inc" do not count. */
function kop_autoconn_key($s) {
    $s = str_replace(array("\u{2019}", "\u{2018}"), "'", (string) $s);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) $s = $t;
    }
    $s = str_replace('&', ' and ', strtolower($s));
    $s = preg_replace("/'s\\b/", 's', $s);
    $s = trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    $s = preg_replace('/^the /', '', $s);
    return preg_replace('/ (inc|llc|ltd|co|corp|l l c)$/', '', $s);
}

/** name key => [facility id => true] over every record's names (name, current, other and past names). */
function kop_autoconn_name_index(PDO $pdo) {
    static $index = null;
    if ($index !== null) return $index;
    $index = array();
    foreach ($pdo->query('SELECT id, name, json_data FROM facilities_v2') as $f) {
        $d = json_decode((string) $f['json_data'], true);
        $ident = is_array($d) ? (array) ($d['identification'] ?? array()) : array();
        $all = array($f['name'], $ident['name'] ?? '', $ident['currentName'] ?? '');
        foreach (array('otherNames', 'pastNames') as $k) {
            foreach ((array) ($ident[$k] ?? array()) as $n) $all[] = is_array($n) ? ($n['name'] ?? '') : $n;
        }
        foreach ($all as $n) {
            $k = kop_autoconn_key($n);
            if ($k !== '') $index[$k][(int) $f['id']] = true;
        }
    }
    return $index;
}

/**
 * True when every name of record $fid is safe to find in running text: ten
 * letters or more ("Day One" is also "from day one"), more than generic words,
 * and no other record's. For queues that do not say which name was found.
 */
function kop_autoconn_record_names_safe(PDO $pdo, $fid) {
    static $by_id = null;
    if ($by_id === null) {
        $by_id = array();
        foreach (kop_autoconn_name_index($pdo) as $k => $ids) {
            foreach ($ids as $id => $_) $by_id[$id][] = (string) $k;
        }
    }
    $keys = $by_id[(int) $fid] ?? array();
    foreach ($keys as $k) {
        if (strlen($k) < 10 || !kop_autoconn_names_only($pdo, $k, $fid)) return false;
    }
    return (bool) $keys;
}

/**
 * True when $name is a name of record $fid and of no other, and says more than
 * generic words ("Children's Home", "Mountain Valley" name no one program in
 * running text).
 */
function kop_autoconn_names_only(PDO $pdo, $name, $fid) {
    $holders = kop_autoconn_name_index($pdo)[kop_autoconn_key($name)] ?? array();
    if (count($holders) !== 1 || !isset($holders[(int) $fid])) return false;
    return !function_exists('kop_wiki_upd_tokens') || (bool) kop_wiki_upd_tokens($name);
}

/* ---- Woodbury Reports pages ------------------------------------------------- */

/**
 * Waiting pages whose name is exactly one record's: an article header of that
 * name (a past name too) with no other record and no state mismatch, or a news
 * item / mention naming it with no other record of the name.
 */
function kop_autoconn_woodbury_rows($limit = 200) {
    global $wpdb;
    $rows = (array) $wpdb->get_results('SELECT * FROM ' . kop_wb_table() . " WHERE status = 'pending' AND facility_id > 0
        AND kind IN ('section', 'news', 'mention') AND (target_kind = 'facility' OR target_kind = '')
        AND (alternatives IS NULL OR alternatives = '' OR alternatives = '[]') AND (reviewed_by = '' OR reviewed_by IS NULL)
        ORDER BY id LIMIT " . (int) $limit, ARRAY_A);
    $tried = kop_autoconn_tried('woodbury-reports');
    $pdo = kop_seed_pdo();
    return array_values(array_filter($rows, function ($r) use ($tried, $pdo) {
        if (isset($tried[$r['ckey']])) return false;
        // The name found (the article's header, or the names the news item or page gives) is this record's alone.
        $found = $r['kind'] === 'section' ? array($r['header']) : explode(', ', (string) $r['matched_name']);
        if (!array_filter($found, function ($n) use ($pdo, $r) { return kop_autoconn_names_only($pdo, $n, $r['facility_id']); })) return false;
        $note = trim((string) $r['note']);
        // "Named on 3 pages" and "Header uses a past name" are about an exact name; anything else is a doubt.
        $note = trim(preg_replace('/^(Named on \d+ pages of this issue\.|Header uses a past name of this record \(a different name era\)\.)/', '', $note));
        return $note === '';
    }));
}

function kop_autoconn_woodbury($deadline) {
    if (!function_exists('kop_wb_file_many')) return 0;
    kop_wb_ensure_table();
    kop_wb_sync();
    $n = 0;
    foreach (kop_autoconn_woodbury_rows() as $r) {
        if (microtime(true) > $deadline) break;
        try {
            kop_wb_file_many($r, array((string) (int) $r['facility_id']), 'auto');
            kop_autoconn_mark('woodbury-reports', $r['ckey'], (string) (int) $r['facility_id'], 'done');
            kop_autoconn_log('woodbury-reports', $r['ckey'], kop_wb_title($r, $r['facility_name']),
                'Filed under ' . $r['facility_name'] . ': the name in the pages is exactly the record\'s.');
            $n++;
        } catch (Throwable $e) {
            kop_autoconn_mark('woodbury-reports', $r['ckey'], (string) (int) $r['facility_id'], 'failed', $e->getMessage());
        }
    }
    return $n;
}

/* ---- Wiki links ------------------------------------------------------------- */

/** The one candidate whose name is exactly the entry's (its own, other or past name, or a company's), or null. */
function kop_autoconn_wiki_exact(array $cands) {
    $exact = array();
    foreach ($cands as $c) {
        if (preg_match('/^(same name|another name of the record|a past name of the record|company name|a company of this name)\b/', (string) ($c['reason'] ?? ''))) {
            $exact[(string) $c['unique_name']] = $c;
        }
    }
    return count($exact) === 1 ? reset($exact) : null;
}

/** [entry id => [entry, candidate]] for unlinked entries with one exact record. */
function kop_autoconn_wiki_picks(PDO $pdo) {
    $log = kop_wiki_upd_link_log();
    $tried = kop_autoconn_tried('wiki-links');
    $out = array();
    foreach (kop_wiki_upd_entries($pdo) as $id => $e) {
        if ($e['kind'] === 'list' || trim((string) $e['facility_unique_name']) !== '' || isset($log[$id]) || isset($tried[(string) $id])) continue;
        $c = kop_autoconn_wiki_exact(kop_wiki_upd_candidates($e, $pdo));
        if (!$c || (string) $c['unique_name'] === '') continue;
        // The name that matched says more than generic words ("The Academy" names no one program).
        $fid = (int) ($c['id'] ?? 0);
        $ok = false;
        foreach (kop_wiki_upd_entry_names($e) as $n) {
            if ($fid > 0 ? kop_autoconn_names_only($pdo, $n, $fid) : (kop_autoconn_key($n) === kop_autoconn_key($c['name']) && kop_wiki_upd_tokens($n))) $ok = true;
        }
        if ($ok) $out[$id] = array($e, $c);
    }
    return $out;
}

function kop_autoconn_wiki($deadline) {
    if (!function_exists('kop_wiki_upd_link') && is_file(__DIR__ . '/wiki-updates.php')) require_once __DIR__ . '/wiki-updates.php';
    if (!function_exists('kop_wiki_upd_link')) return 0;
    $pdo = kop_wiki_upd_pdo();
    $n = 0;
    foreach (kop_autoconn_wiki_picks($pdo) as $id => list($e, $c)) {
        if (microtime(true) > $deadline) break;
        try {
            kop_wiki_upd_link($pdo, $id, (string) $c['unique_name'], 'auto', 'link', (string) $c['name']);
            kop_autoconn_mark('wiki-links', $id, (string) $c['unique_name'], 'done');
            kop_autoconn_log('wiki-links', $id, (string) $e['program_name'],
                'Linked to ' . $c['name'] . ': the only record of exactly this name (' . $c['reason'] . ').');
            $n++;
        } catch (Throwable $e2) {
            kop_autoconn_mark('wiki-links', $id, (string) $c['unique_name'], 'failed', $e2->getMessage());
        }
    }
    if ($n) delete_transient('kop_rinbox_wiki_links_v3');
    return $n;
}

/* ---- Fornits discussion links -------------------------------------------- */

/**
 * Waiting discussion links named in the posts' own text (the opening post, or
 * several posts) with no other record of that name. A board's program or a
 * title match alone waits (the title reader also takes close names).
 */
function kop_autoconn_fornits_rows($limit = 400) {
    global $wpdb;
    $rows = (array) $wpdb->get_results('SELECT * FROM ' . kop_fornits_items_table() . " WHERE kind = 'link' AND status = 'pending' AND facility_id > 0
        AND (also IS NULL OR also = '' OR also = '[]') AND (reviewed_by = '' OR reviewed_by IS NULL)
        AND (how LIKE '%opening post%' OR how LIKE '%named in %') ORDER BY facility_id, id LIMIT " . (int) $limit, ARRAY_A);
    $tried = kop_autoconn_tried('fornits');
    $pdo = kop_seed_pdo();
    $safe = array();
    return array_values(array_filter($rows, function ($r) use ($tried, $pdo, &$safe) {
        if (isset($tried[$r['pkey']])) return false;
        $fid = (int) $r['facility_id'];
        if (!isset($safe[$fid])) $safe[$fid] = kop_autoconn_record_names_safe($pdo, $fid);
        return $safe[$fid];
    }));
}

function kop_autoconn_fornits($deadline) {
    if (!function_exists('kop_fornits_apply')) return 0;
    kop_fornits_ensure_tables();
    $by_fid = array();
    foreach (kop_autoconn_fornits_rows() as $r) $by_fid[(int) $r['facility_id']][] = $r;
    $n = 0;
    foreach ($by_fid as $fid => $rows) {
        if (microtime(true) > $deadline) break;
        try {
            $res = kop_fornits_apply($rows, array(), 0, 'auto');
        } catch (Throwable $e) {
            foreach ($rows as $r) kop_autoconn_mark('fornits', $r['pkey'], (string) $fid, 'failed', $e->getMessage());
            continue;
        }
        foreach ($rows as $r) {
            $ok = !empty($res[$r['pkey']]['ok']);
            kop_autoconn_mark('fornits', $r['pkey'], (string) $fid, $ok ? 'done' : 'failed', $ok ? '' : (string) ($res[$r['pkey']]['error'] ?? ''));
            if (!$ok) continue;
            $n++;
            kop_autoconn_log('fornits', $r['pkey'], (string) $r['label'],
                'Added under "Survivor posts and discussion": the thread names the program exactly (' . $r['how'] . ').');
        }
    }
    return $n;
}

/* ---- State Lists ------------------------------------------------------------ */

/** [row id => facility id]: rows waiting (never put off by a person) whose listed name is now exactly one record's in that state. */
function kop_autoconn_state_list_picks(PDO $pdo) {
    $rows = $pdo->query('SELECT * FROM `' . kop_sl_table() . "` WHERE status = 'open' AND (decided_by IS NULL OR decided_by = '')")->fetchAll(PDO::FETCH_ASSOC);
    $tried = kop_autoconn_tried('state-lists');
    $out = array();
    foreach (kop_sl_on_file($pdo, $rows) as $rid => $fid) {
        if (!isset($tried[(string) $rid])) $out[$rid] = $fid;
    }
    return $out;
}

function kop_autoconn_state_lists($deadline) {
    if (!function_exists('kop_sl_act')) return 0;
    $pdo = kop_sl_pdo();
    if (!$pdo) return 0;
    kop_sl_ensure_table($pdo);
    $n = 0;
    foreach (kop_autoconn_state_list_picks($pdo) as $rid => $fid) {
        if (microtime(true) > $deadline) break;
        try {
            kop_sl_act($pdo, $rid, 'link', array('facility_id' => $fid), 'auto');
            kop_autoconn_mark('state-lists', $rid, (string) $fid, 'done');
            $n++;
        } catch (Throwable $e) {
            kop_autoconn_mark('state-lists', $rid, (string) $fid, 'failed', $e->getMessage());
        }
    }
    return $n;
}

/* ---- The run ---------------------------------------------------------------- */

/** Connect what is exact in every queue, cheapest first, until $seconds run out. [source => count]. */
function kop_autoconn_run($seconds = 120) {
    if (get_transient('kop_auto_connect_lock')) return array();
    set_transient('kop_auto_connect_lock', 1, 10 * MINUTE_IN_SECONDS);
    $deadline = microtime(true) + $seconds;
    $done = array();
    try {
        kop_autoconn_ensure_table();
        foreach (array('wiki-links' => 'kop_autoconn_wiki', 'state-lists' => 'kop_autoconn_state_lists',
                'fornits' => 'kop_autoconn_fornits', 'woodbury-reports' => 'kop_autoconn_woodbury') as $source => $fn) {
            if (microtime(true) > $deadline) break;
            try {
                $done[$source] = $fn($deadline);
            } catch (Throwable $e) {
                error_log('kop auto connect ' . $source . ': ' . $e->getMessage());
            }
        }
        update_option('kop_auto_connect_last_run', array('at' => current_time('mysql', true), 'done' => $done), false);
    } finally {
        delete_transient('kop_auto_connect_lock');
    }
    return $done;
}

add_action('init', function () {
    if (!wp_next_scheduled('kop_auto_connect_hourly')) {
        wp_schedule_event(time() + 600, 'hourly', 'kop_auto_connect_hourly');
    }
});
add_action('kop_auto_connect_hourly', function () {
    kop_autoconn_run(150);
});
