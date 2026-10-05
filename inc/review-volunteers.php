<?php
/**
 * Volunteer reviewers: people without a WordPress account help with the
 * review inbox (inc/review-inbox.php) through a personal invite link.
 *
 * KOP Tools > Volunteer Reviewers makes a link for a name
 * (/volunteer-review/?invite=<token>). Opening it once sets the kop_vol cookie
 * and drops the token from the address bar; the page then works on that
 * device with no username or password. Only a hash of the token is stored, so
 * a lost link is replaced ("New link"), never shown again. "Turn off" stops
 * one person's link without touching anyone else's.
 *
 * Volunteers only recommend. Their Approve / Reject / Not sure (with a note)
 * is a row in {prefix}kop_volunteer_recs; nothing on the site changes until an
 * admin acts on the item in the inbox, where every card shows what volunteers
 * said and "Volunteers recommend" lists the items they have looked at. When
 * an admin approves or rejects an item (kop_rinbox_log_insert()), its open
 * recommendations are closed with that outcome, which gives each volunteer an
 * agreement rate on the admin screen.
 *
 * Guardrails:
 * - A volunteer sees only the queues ticked on the admin screen; queues that
 *   hold readers' contact details or unpublished survivor material
 *   (kop_vol_never_sources()) cannot be ticked.
 * - Items reach the volunteer as a copy (kop_vol_item()) with no tags, holds,
 *   edit fields, actions or reviewer notes, and email addresses and phone
 *   numbers blanked; held items (snoozed or handed to an admin) are left out.
 * - Every volunteer route is POST (never cached by the host), needs the
 *   cookie and a per-link header the page prints (no cross-site requests),
 *   and is rate limited.
 * - Admins can open /volunteer-review/ to see what volunteers see.
 *
 * REST (volunteer cookie + X-KOP-Vol header, or manage_options):
 * kop/v1/volunteer/{queues, items, recommend, mine}.
 * Tested by scripts/test-review-volunteers.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_VOL_DB_VERSION = '1';
const KOP_VOL_COOKIE = 'kop_vol';
const KOP_VOL_PATH = 'volunteer-review';
const KOP_VOL_REWRITE_VERSION = '1';
const KOP_VOL_HOURLY_LIMIT = 300;

/* ---- Tables --------------------------------------------------------------- */

function kop_vol_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_volunteers';
}

function kop_vol_recs_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_volunteer_recs';
}

function kop_vol_ensure_tables() {
    if (get_option('kop_volunteers_db') === KOP_VOL_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $v = kop_vol_table();
    $r = kop_vol_recs_table();
    dbDelta("CREATE TABLE $v (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        token_hash CHAR(64) NOT NULL,
        created_by VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        link_at DATETIME NOT NULL,
        last_seen DATETIME NULL,
        revoked_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY token_hash (token_hash)
    ) $c;");
    dbDelta("CREATE TABLE $r (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        volunteer_id BIGINT UNSIGNED NOT NULL,
        source VARCHAR(40) NOT NULL,
        item_key VARCHAR(191) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        verdict VARCHAR(12) NOT NULL,
        note TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        outcome VARCHAR(12) NOT NULL DEFAULT '',
        outcome_by VARCHAR(60) NOT NULL DEFAULT '',
        outcome_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY one_each (volunteer_id, source, item_key),
        KEY item (source, item_key),
        KEY outcome (outcome)
    ) $c;");
    update_option('kop_volunteers_db', KOP_VOL_DB_VERSION);
}

/* ---- Which queues ----------------------------------------------------------- */

/** Queues volunteers can never see: readers' contact details, unpublished survivor material, nothing to judge. */
function kop_vol_never_sources() {
    return array('anonymous-docs', 'bug-reports', 'glossary-feedback', 'fornits', 'data', 'publish-approved');
}

/** Ticked when nobody has chosen yet: things a careful reader can judge from the page itself. */
function kop_vol_default_sources() {
    return array('news', 'lawsuit', 'legislation', 'wiki', 'scraper-finds', 'facilities-from-news', 'closure', 'woodbury-reports');
}

/** The queues volunteers see now: [key => source spec]. */
function kop_vol_sources() {
    $chosen = get_option('kop_volunteer_sources', null);
    $chosen = is_array($chosen) ? $chosen : kop_vol_default_sources();
    $out = array();
    $all = function_exists('kop_rinbox_sources') ? kop_rinbox_sources() : array();
    foreach ($chosen as $key) {
        if (isset($all[$key]) && !in_array($key, kop_vol_never_sources(), true)) $out[$key] = $all[$key];
    }
    return $out;
}

function kop_vol_source($key) {
    $all = kop_vol_sources();
    if (!isset($all[$key])) throw new InvalidArgumentException('That queue is not open to volunteers.');
    return $all[$key];
}

/** What to look for, per queue, in plain words. Falls back to the queue's own help. */
function kop_vol_guidance($key) {
    $g = array(
        'news'        => 'Is the article about a troubled teen program, abuse in one, or the industry around them (consultants, transport, owners, laws)? Does the link work, and is it a real news story rather than a program\'s own advertising? If it is already on the site under another link, say so in the note.',
        'lawsuit'     => 'Is this a real court case about a program, its staff or its owners? Check that the case name, court and dates match the source. Say in the note if anything looks wrong.',
        'legislation' => 'Is this a real bill about youth residential programs, restraint, seclusion, or oversight of them? Check the bill number and state against the official link.',
        'wiki'        => 'Is the write-up about the program it names, factual, and free of personal attacks on private people? Note anything that looks invented or copied.',
        'scraper-finds' => 'The news scanner set these aside. Recommend approve when the article is really about a troubled teen program or the industry; reject when it is about something else (a sports team, a summer camp with no residential program, an unrelated "academy").',
        'facilities-from-news' => 'The news scan found a program name with no record. Is it a real residential program for young people (not an adult rehab, a hospital ward or a school with no boarding)? Is it already on the site under another name?',
        'closure'     => 'Does the article really say this program closed (or is closing)? Recommend reject when it is about a different program with a similar name, or only a rumour.',
        'woodbury-reports' => 'Does this Woodbury Reports page really talk about the program it is filed under? Reject when the name only matches by chance.',
    );
    if (isset($g[$key])) return $g[$key];
    $src = kop_vol_sources()[$key] ?? null;
    return $src ? (string) ($src['help'] ?? '') : '';
}

/* ---- Who is reviewing --------------------------------------------------------- */

function kop_vol_hash($token) {
    return hash('sha256', (string) $token);
}

function kop_vol_new_token() {
    return bin2hex(random_bytes(24));
}

/** The volunteer row for a token, or null when unknown or turned off. */
function kop_vol_by_token($token) {
    $token = (string) $token;
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    global $wpdb;
    kop_vol_ensure_tables();
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_vol_table() . ' WHERE token_hash = %s', kop_vol_hash($token)), ARRAY_A);
    return ($row && empty($row['revoked_at'])) ? $row : null;
}

/** The volunteer this browser belongs to (from the cookie), or null. Read once a request. */
function kop_vol_current() {
    static $cur = false;
    if ($cur !== false) return $cur;
    $cur = null;
    $token = isset($_COOKIE[KOP_VOL_COOKIE]) ? (string) wp_unslash($_COOKIE[KOP_VOL_COOKIE]) : '';
    if ($token === '') return $cur;
    try {
        $cur = kop_vol_by_token($token);
    } catch (Throwable $e) {
        $cur = null;
    }
    if ($cur) {
        $cur['token'] = $token;
        // Last seen, at most every ten minutes.
        if (empty($cur['last_seen']) || strtotime($cur['last_seen'] . ' UTC') < time() - 600) {
            global $wpdb;
            $wpdb->update(kop_vol_table(), array('last_seen' => current_time('mysql', true)), array('id' => (int) $cur['id']));
        }
    }
    return $cur;
}

/** The header value the page sends with every request: ties the request to the page, not just the cookie. */
function kop_vol_csrf($token) {
    return substr(hash_hmac('sha256', 'kop-vol|' . $token, wp_salt('nonce')), 0, 32);
}

function kop_vol_set_cookie($token) {
    $opts = array('expires' => time() + YEAR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax');
    setcookie(KOP_VOL_COOKIE, $token, $opts);
    $_COOKIE[KOP_VOL_COOKIE] = $token;
}

function kop_vol_page_url() {
    return home_url('/' . KOP_VOL_PATH . '/');
}

function kop_vol_invite_url($token) {
    return add_query_arg('invite', $token, kop_vol_page_url());
}

/* ---- Volunteers (admin) ------------------------------------------------------ */

/** Make a volunteer and their link. Returns [id, url]; the url is shown once. */
function kop_vol_create($name, $note = '') {
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
    if ($name === '') throw new RuntimeException('Give the volunteer a name.');
    global $wpdb;
    kop_vol_ensure_tables();
    $token = kop_vol_new_token();
    $now = current_time('mysql', true);
    $u = wp_get_current_user();
    $wpdb->insert(kop_vol_table(), array(
        'name' => mb_substr($name, 0, 100), 'note' => mb_substr(trim((string) $note), 0, 255),
        'token_hash' => kop_vol_hash($token), 'created_by' => $u && $u->exists() ? $u->user_login : '',
        'created_at' => $now, 'link_at' => $now,
    ));
    return array((int) $wpdb->insert_id, kop_vol_invite_url($token));
}

/** Replace a volunteer's link (the old one stops working) and turn them back on. Returns the new url. */
function kop_vol_new_link($id) {
    global $wpdb;
    kop_vol_ensure_tables();
    $token = kop_vol_new_token();
    $n = $wpdb->update(kop_vol_table(), array('token_hash' => kop_vol_hash($token), 'link_at' => current_time('mysql', true), 'revoked_at' => null), array('id' => (int) $id));
    if (!$n) throw new RuntimeException('No such volunteer.');
    return kop_vol_invite_url($token);
}

function kop_vol_revoke($id) {
    global $wpdb;
    kop_vol_ensure_tables();
    $wpdb->update(kop_vol_table(), array('revoked_at' => current_time('mysql', true)), array('id' => (int) $id));
}

function kop_vol_rename($id, $name) {
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
    if ($name === '') throw new RuntimeException('The name cannot be empty.');
    global $wpdb;
    $wpdb->update(kop_vol_table(), array('name' => mb_substr($name, 0, 100)), array('id' => (int) $id));
}

/** Every volunteer with how many they recommended and how often the admin decided the same way. */
function kop_vol_list() {
    global $wpdb;
    kop_vol_ensure_tables();
    $v = kop_vol_table();
    $r = kop_vol_recs_table();
    return (array) $wpdb->get_results("SELECT v.*,
            (SELECT COUNT(*) FROM $r WHERE volunteer_id = v.id) AS recs,
            (SELECT COUNT(*) FROM $r WHERE volunteer_id = v.id AND outcome IN ('approve', 'reject') AND verdict IN ('approve', 'reject')) AS decided,
            (SELECT COUNT(*) FROM $r WHERE volunteer_id = v.id AND outcome IN ('approve', 'reject') AND verdict = outcome) AS agreed
        FROM $v v ORDER BY v.revoked_at IS NOT NULL, v.name", ARRAY_A);
}

/* ---- Recommendations ---------------------------------------------------------- */

function kop_vol_verdicts() {
    return array('approve' => 'Approve', 'reject' => 'Reject', 'unsure' => 'Not sure');
}

/** Save (or change) a volunteer's recommendation on an item that is still waiting. */
function kop_vol_recommend(array $vol, $source, $key, $verdict, $note) {
    $verdict = (string) $verdict;
    if (!isset(kop_vol_verdicts()[$verdict])) throw new RuntimeException('Choose approve, reject or not sure.');
    $note = trim(mb_substr((string) $note, 0, 1000));
    if ($verdict === 'unsure' && $note === '') throw new RuntimeException('Say in the note what you are not sure about.');
    $src = kop_vol_source($source);
    $item = kop_vol_raw_item($src, $source, $key);
    if (!$item || !kop_vol_actionable($source, $item)) throw new RuntimeException('Someone has already dealt with this one. Thanks anyway.');
    if (isset(kop_vol_held_keys($source)[(string) $key])) throw new RuntimeException('An admin has set this one aside.');
    kop_vol_rate_check((int) $vol['id']);

    global $wpdb;
    kop_vol_ensure_tables();
    $t = kop_vol_recs_table();
    $now = current_time('mysql', true);
    $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE volunteer_id = %d AND source = %s AND item_key = %s", (int) $vol['id'], $source, (string) $key), ARRAY_A);
    if ($old && $old['outcome'] !== '') throw new RuntimeException('An admin has already decided this one.');
    $row = array('title' => mb_substr((string) $item['title'], 0, 255), 'verdict' => $verdict, 'note' => $note, 'updated_at' => $now);
    if ($old) {
        $wpdb->update($t, $row, array('id' => (int) $old['id']));
    } else {
        $wpdb->insert($t, $row + array('volunteer_id' => (int) $vol['id'], 'source' => $source, 'item_key' => (string) $key, 'created_at' => $now));
    }
    delete_transient('kop_vol_open_count');
    return array('message' => 'Thanks. Your recommendation is saved; an admin makes the final call.');
}

/** Take back a recommendation an admin has not acted on. */
function kop_vol_withdraw(array $vol, $source, $key) {
    global $wpdb;
    kop_vol_ensure_tables();
    $wpdb->query($wpdb->prepare('DELETE FROM ' . kop_vol_recs_table() . " WHERE volunteer_id = %d AND source = %s AND item_key = %s AND outcome = ''",
        (int) $vol['id'], (string) $source, (string) $key));
    delete_transient('kop_vol_open_count');
    return array('message' => 'Taken back.');
}

function kop_vol_rate_check($vid) {
    $k = 'kop_vol_rate_' . (int) $vid;
    $n = (int) get_transient($k);
    if ($n >= KOP_VOL_HOURLY_LIMIT) throw new RuntimeException('That is a lot in one hour. Take a break and come back later.');
    set_transient($k, $n + 1, HOUR_IN_SECONDS);
}

/**
 * Close the open recommendations on an item an admin just approved or
 * rejected (called from kop_rinbox_log_insert()). 'gone' / 'dismissed' close
 * them without counting toward agreement.
 */
function kop_vol_resolve($source, $key, $outcome, $by = '') {
    if (!in_array($outcome, array('approve', 'reject', 'gone', 'dismissed'), true)) return;
    try {
        global $wpdb;
        kop_vol_ensure_tables();
        $wpdb->query($wpdb->prepare('UPDATE ' . kop_vol_recs_table() . " SET outcome = %s, outcome_by = %s, outcome_at = %s WHERE source = %s AND item_key = %s AND outcome = ''",
            $outcome, (string) $by, current_time('mysql', true), (string) $source, (string) $key));
        delete_transient('kop_vol_open_count');
    } catch (Throwable $e) {
        // Never in the way of the review itself.
    }
}

/** An Undo in Recently done reopens what volunteers said about the item. */
function kop_vol_reopen($source, $key) {
    try {
        global $wpdb;
        kop_vol_ensure_tables();
        $wpdb->query($wpdb->prepare('UPDATE ' . kop_vol_recs_table() . " SET outcome = '', outcome_by = '', outcome_at = NULL WHERE source = %s AND item_key = %s AND outcome IN ('approve', 'reject')",
            (string) $source, (string) $key));
        delete_transient('kop_vol_open_count');
    } catch (Throwable $e) {
        // Never in the way of the Undo.
    }
}

/** What volunteers said about these items: [key => [{name, verdict, verdict_label, note, at}]], open ones only. */
function kop_vol_recs_for($source, array $keys) {
    $out = array();
    if (!$keys) return $out;
    try {
        global $wpdb;
        kop_vol_ensure_tables();
        $ph = implode(',', array_fill(0, count($keys), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT r.item_key, r.verdict, r.note, r.updated_at, v.name FROM ' . kop_vol_recs_table() . ' r JOIN ' . kop_vol_table() . " v ON v.id = r.volunteer_id
             WHERE r.source = %s AND r.outcome = '' AND r.item_key IN ($ph) ORDER BY r.updated_at",
            array_merge(array((string) $source), array_map('strval', $keys))
        ), ARRAY_A);
    } catch (Throwable $e) {
        return $out;
    }
    $labels = kop_vol_verdicts();
    foreach ((array) $rows as $r) {
        $out[$r['item_key']][] = array('name' => $r['name'], 'verdict' => $r['verdict'], 'verdict_label' => $labels[$r['verdict']] ?? $r['verdict'],
            'note' => (string) $r['note'], 'at' => $r['updated_at'] . 'Z');
    }
    return $out;
}

/** How many items wait with an open recommendation (the admin tab's count), cached a minute. */
function kop_vol_open_count() {
    $n = get_transient('kop_vol_open_count');
    if ($n !== false) return (int) $n;
    try {
        global $wpdb;
        kop_vol_ensure_tables();
        $n = (int) $wpdb->get_var('SELECT COUNT(*) FROM (SELECT 1 FROM ' . kop_vol_recs_table() . " WHERE outcome = '' GROUP BY source, item_key) x");
    } catch (Throwable $e) {
        $n = 0;
    }
    set_transient('kop_vol_open_count', $n, MINUTE_IN_SECONDS);
    return $n;
}

/**
 * The admin inbox's "Volunteers recommend" list: items with open
 * recommendations, the most agreed first. An item that left its queue some
 * other way (an old screen) is closed as 'gone'.
 */
function kop_vol_admin_items() {
    global $wpdb;
    kop_vol_ensure_tables();
    $rows = $wpdb->get_results('SELECT source, item_key, COUNT(*) AS n, MAX(updated_at) AS last FROM ' . kop_vol_recs_table()
        . " WHERE outcome = '' GROUP BY source, item_key ORDER BY n DESC, last DESC LIMIT 150", ARRAY_A);
    $sources = kop_rinbox_sources();
    $items = array();
    foreach ((array) $rows as $r) {
        if (!isset($sources[$r['source']])) continue;
        try {
            $item = kop_rinbox_get_item($r['source'], $r['item_key']);
        } catch (Throwable $e) {
            kop_vol_resolve($r['source'], $r['item_key'], 'gone');
            continue;
        }
        if (!kop_vol_actionable($r['source'], $item)) {
            kop_vol_resolve($r['source'], $r['item_key'], 'gone');
            continue;
        }
        $item['source'] = $r['source'];
        $item['source_label'] = $sources[$r['source']]['label'];
        $item['native'] = !empty($sources[$r['source']]['native']);
        $items[] = $item;
        if (count($items) >= 100) break;
    }
    return array('items' => $items, 'total' => count($items));
}

/* ---- Items, as volunteers see them -------------------------------------------- */

/** The item straight from its queue (no tags or holds), or null. */
function kop_vol_raw_item(array $src, $source, $key) {
    if (empty($src['get'])) return null;
    try {
        $it = call_user_func($src['get'], (string) $key);
    } catch (Throwable $e) {
        return null;
    }
    return is_array($it) ? $it : null;
}

/** Still waiting on a decision: a pending page-type row, or a queue item that offers Approve or Reject. */
function kop_vol_actionable($source, array $item) {
    if (function_exists('kop_rinbox_native_types') && isset(kop_rinbox_native_types()[$source])) {
        return (string) ($item['status'] ?? '') === kop_rinbox_native_types()[$source]['pending'];
    }
    foreach ((array) ($item['actions'] ?? array()) as $a) {
        if (in_array($a['style'] ?? '', array('approve', 'reject'), true)) return true;
    }
    return false;
}

/** Snoozed or handed to an admin: not for volunteers. */
function kop_vol_held_keys($source) {
    $out = array();
    if (!function_exists('kop_rinbox_holds_active')) return $out;
    foreach (kop_rinbox_holds_active($source) as $k => $h) $out[(string) $k] = true;
    return $out;
}

/** Email addresses and phone numbers blanked out of any text a volunteer sees. */
function kop_vol_scrub($text) {
    $text = (string) $text;
    $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email removed]', $text);
    $text = preg_replace('/(?<![\d\w])(?:\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}(?!\d)/', '[phone removed]', $text);
    return $text;
}

/** A detail or field label that names who sent something or how to reach them. */
function kop_vol_private_label($label) {
    return (bool) preg_match('/e-?mail|contact|phone|sent by|submitted by|submitter|reporter|browser|\bip\b|user agent|reviewer|notes? from/i', (string) $label);
}

/** One item as a volunteer sees it: what it is, its links and facts, and what Approve and Reject would do. */
function kop_vol_item($source, array $it) {
    $s = 'kop_vol_scrub';
    $facts = array();
    foreach ((array) ($it['fields'] ?? array()) as $f) {
        if (kop_vol_private_label($f['label'] ?? '') || !empty($f['private'])) continue;
        $v = $f['value'] ?? '';
        $type = $f['type'] ?? 'text';
        if ($type === 'facility' || $type === 'facilities' || $type === 'checkbox') continue;
        if ($type === 'select' && isset($f['options'][(string) $v])) $v = $f['options'][(string) $v];
        if (is_array($v)) $v = implode('; ', array_map('strval', $v));
        $v = trim((string) $v);
        if ($v === '' || $v === trim((string) ($it['title'] ?? ''))) continue;
        $facts[] = array('label' => (string) ($f['label'] ?? ''), 'value' => $s(kop_rinbox_excerpt($v, 2000)));
    }
    foreach ((array) ($it['details'] ?? array()) as $d) {
        if (!is_array($d) || kop_vol_private_label($d['label'] ?? '')) continue;
        $v = is_array($d['value'] ?? '') ? implode('; ', array_map('strval', $d['value'])) : (string) ($d['value'] ?? '');
        if (trim($v) === '') continue;
        $url = (string) ($d['url'] ?? '');
        $facts[] = array('label' => (string) ($d['label'] ?? ''), 'value' => $s(kop_rinbox_excerpt($v, 2000)),
            'url' => preg_match('#^https?://#i', $url) ? $url : '');
    }
    $links = array();
    foreach ((array) ($it['links'] ?? array()) as $l) {
        if (!empty($l['url']) && preg_match('#^https?://#i', (string) $l['url']) && !kop_vol_private_label($l['label'] ?? '')) {
            $links[] = array('label' => $s((string) ($l['label'] ?? $l['url'])), 'url' => (string) $l['url']);
        }
    }
    if (!empty($it['preview']['url']) && preg_match('#^https?://#i', (string) $it['preview']['url'])) {
        $links[] = array('label' => (string) ($it['preview']['label'] ?? 'The document'), 'url' => (string) $it['preview']['url']);
    }
    // What each choice would do, from the queue's own help text.
    $approve = array();
    $reject = array();
    if (isset($it['approve_help'])) {
        $approve[] = (string) $it['approve_help'];
        $reject[] = (string) ($it['reject_help'] ?? '');
    }
    foreach ((array) ($it['actions'] ?? array()) as $a) {
        $style = $a['style'] ?? '';
        if ($style !== 'approve' && $style !== 'reject') continue;
        $line = (string) ($a['label'] ?? '') . (!empty($a['help']) ? ': ' . $a['help'] : '');
        if ($style === 'approve') $approve[] = $line; else $reject[] = $line;
    }
    $compare = null;
    if (!empty($it['compare']['rows'])) {
        $compare = array('heads' => array_map($s, array_map('strval', (array) ($it['compare']['heads'] ?? array()))), 'rows' => array());
        foreach ((array) $it['compare']['rows'] as $row) {
            if (kop_vol_private_label($row['label'] ?? '')) continue;
            $compare['rows'][] = array('label' => (string) ($row['label'] ?? ''), 'values' => array_map(function ($v) use ($s) {
                return $s(is_array($v) ? implode('; ', array_map('strval', $v)) : (string) $v);
            }, (array) ($row['values'] ?? array())));
        }
    }
    $fac = $it['facility'] ?? null;
    return array(
        'source'   => $source,
        'key'      => (string) $it['key'],
        'title'    => $s((string) ($it['title'] ?? '')),
        'subtitle' => $s((string) ($it['subtitle'] ?? '')),
        'url'      => preg_match('#^https?://#i', (string) ($it['url'] ?? '')) ? (string) $it['url'] : '',
        'text'     => $s(kop_rinbox_excerpt((string) ($it['text'] ?? ''), 3000)),
        'created'  => substr((string) ($it['created'] ?? ''), 0, 10),
        'facility' => is_array($fac) ? array('name' => (string) ($fac['name'] ?? ''), 'url' => (string) ($fac['url'] ?? '')) : null,
        'facts'    => $facts,
        'links'    => $links,
        'compare'  => $compare,
        'approve'  => array_values(array_filter(array_map($s, $approve), 'strlen')),
        'reject'   => array_values(array_filter(array_map($s, $reject), 'strlen')),
    );
}

/**
 * Items of a queue waiting for this volunteer: not held, not already
 * recommended by them, still actionable. Pages through the queue's own list.
 */
function kop_vol_items($vid, $source, $offset, $limit, $search = '') {
    $src = kop_vol_source($source);
    $skip = kop_vol_held_keys($source);
    if ($vid) {
        global $wpdb;
        kop_vol_ensure_tables();
        foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_vol_recs_table() . ' WHERE volunteer_id = %d AND source = %s', (int) $vid, $source)) as $k) {
            $skip[(string) $k] = true;
        }
    }
    $views = array_keys((array) $src['views']);
    $items = array();
    $total = 0;
    for ($i = 0; $i < 10 && count($items) < $limit; $i++) {
        $res = call_user_func($src['list'], array('view' => $views[0], 'search' => $search, 'offset' => $offset, 'limit' => 25, 'origin' => '', 'filters' => array()));
        $page = (array) ($res['items'] ?? array());
        $total = (int) ($res['total'] ?? 0);
        $full = false;
        foreach ($page as $it) {
            $offset++;
            if (isset($skip[(string) $it['key']]) || !kop_vol_actionable($source, $it)) continue;
            $items[] = kop_vol_item($source, $it);
            if (count($items) >= $limit) { $full = true; break; }
        }
        // The queue's last page, read to its end: nothing more to load.
        if (!$full && count($page) < 25) { $offset = -1; break; }
    }
    return array('items' => $items, 'next_offset' => $offset, 'total' => $total);
}

/** The volunteer's own recommendations, newest first, with what the admin decided. */
function kop_vol_mine($vid) {
    global $wpdb;
    kop_vol_ensure_tables();
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_vol_recs_table() . ' WHERE volunteer_id = %d ORDER BY updated_at DESC LIMIT 200', (int) $vid), ARRAY_A);
    $sources = function_exists('kop_rinbox_sources') ? kop_rinbox_sources() : array();
    $labels = kop_vol_verdicts();
    $out = array();
    foreach ((array) $rows as $r) {
        $out[] = array('source' => $r['source'], 'source_label' => $sources[$r['source']]['label'] ?? $r['source'], 'key' => $r['item_key'],
            'title' => kop_vol_scrub($r['title']), 'verdict' => $r['verdict'], 'verdict_label' => $labels[$r['verdict']] ?? $r['verdict'],
            'note' => (string) $r['note'], 'at' => $r['updated_at'] . 'Z', 'outcome' => $r['outcome']);
    }
    return $out;
}

/* ---- REST ------------------------------------------------------------------- */

/** Who is asking: ['vol' => row] for a volunteer, ['admin' => true] for an admin, or a WP_Error. */
function kop_vol_rest_who(WP_REST_Request $req) {
    nocache_headers();
    header('X-LiteSpeed-Cache-Control: no-cache');
    $vol = kop_vol_current();
    if ($vol) {
        if (!hash_equals(kop_vol_csrf($vol['token']), (string) $req->get_header('x_kop_vol'))) {
            return new WP_Error('kop_vol_page', 'Reload the page and try again.', array('status' => 403));
        }
        return array('vol' => $vol);
    }
    if (current_user_can('manage_options')) return array('admin' => true);
    return new WP_Error('kop_vol_link', 'Open your invite link again to sign in on this device.', array('status' => 401));
}

add_action('rest_api_init', function () {
    $routes = array('queues' => 'kop_vol_rest_queues', 'items' => 'kop_vol_rest_items', 'recommend' => 'kop_vol_rest_recommend', 'mine' => 'kop_vol_rest_mine');
    foreach ($routes as $path => $cb) {
        register_rest_route('kop/v1', '/volunteer/' . $path, array(
            'methods' => 'POST', 'callback' => $cb,
            'permission_callback' => function (WP_REST_Request $req) {
                $who = kop_vol_rest_who($req);
                return is_wp_error($who) ? $who : true;
            },
        ));
    }
});

function kop_vol_rest_queues(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $counts = kop_rinbox_counts();
        $out = array();
        foreach (kop_vol_sources() as $key => $src) {
            $out[] = array('key' => $key, 'label' => $src['label'], 'count' => isset($counts[$key]) ? (int) $counts[$key] : null,
                'guidance' => kop_vol_guidance($key));
        }
        return array('queues' => $out);
    });
}

function kop_vol_rest_items(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        $vid = isset($who['vol']) ? (int) $who['vol']['id'] : 0;
        return kop_vol_items($vid, (string) $req->get_param('source'), max(0, (int) $req->get_param('offset')),
            max(1, min(20, (int) ($req->get_param('limit') ?: 10))), trim((string) $req->get_param('search')));
    });
}

function kop_vol_rest_recommend(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        if (empty($who['vol'])) throw new RuntimeException('Admins see this page as a preview; recommend from your own inbox.');
        $source = (string) $req->get_param('source');
        $key = (string) $req->get_param('key');
        if ($req->get_param('withdraw')) return kop_vol_withdraw($who['vol'], $source, $key);
        return kop_vol_recommend($who['vol'], $source, $key, (string) $req->get_param('verdict'), wp_unslash((string) $req->get_param('note')));
    });
}

function kop_vol_rest_mine(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        return array('rows' => !empty($who['vol']) ? kop_vol_mine((int) $who['vol']['id']) : array());
    });
}

/* ---- The page: /volunteer-review/ ---------------------------------------------- */

add_action('init', function () {
    add_rewrite_rule('^' . KOP_VOL_PATH . '/?$', 'index.php?kop_volreview=1', 'top');
    if (get_option('kop_volreview_rewrite') !== KOP_VOL_REWRITE_VERSION) {
        flush_rewrite_rules(false);
        update_option('kop_volreview_rewrite', KOP_VOL_REWRITE_VERSION);
    }
}, 30);

add_filter('query_vars', function ($vars) {
    $vars[] = 'kop_volreview';
    return $vars;
});

function kop_vol_is_page() {
    return function_exists('get_query_var') && get_query_var('kop_volreview') === '1';
}

add_action('pre_get_posts', function ($query) {
    if (is_admin() || !$query->is_main_query() || $query->get('kop_volreview') !== '1') return;
    $query->is_home = false;
    $query->set('posts_per_page', 1);
    $query->set('no_found_rows', true);
});
add_filter('posts_pre_query', function ($posts, $query) {
    return (!is_admin() && $query->is_main_query() && $query->get('kop_volreview') === '1') ? array() : $posts;
}, 10, 2);
add_filter('pre_handle_404', function ($preempt, $query) {
    return $query->get('kop_volreview') === '1' ? true : $preempt;
}, 10, 2);

/** Opening an invite link: set the cookie and send the browser on without the token in the address. */
add_action('template_redirect', function () {
    if (!kop_vol_is_page()) return;
    nocache_headers();
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Referrer-Policy: no-referrer', true);
    status_header(200);
    if (!isset($_GET['invite'])) return;
    $token = strtolower(trim((string) wp_unslash($_GET['invite'])));
    $vol = kop_vol_by_token($token);
    if (!$vol) {
        $GLOBALS['kop_vol_bad_invite'] = true;
        return;
    }
    kop_vol_set_cookie($token);
    wp_safe_redirect(kop_vol_page_url());
    exit;
}, 5);

add_filter('template_include', function ($template) {
    if (!kop_vol_is_page()) return $template;
    $t = get_stylesheet_directory() . '/templates/volunteer-review.php';
    return file_exists($t) ? $t : $template;
}, 99);

add_filter('pre_get_document_title', function ($title) {
    return kop_vol_is_page() ? 'Help review submissions | Kids Over Profits' : $title;
}, 20);

add_filter('wp_robots', function ($robots) {
    if (kop_vol_is_page()) { $robots['noindex'] = true; $robots['nofollow'] = true; }
    return $robots;
});

add_action('wp_enqueue_scripts', function () {
    if (!kop_vol_is_page()) return;
    $vol = kop_vol_current();
    $admin = !$vol && current_user_can('manage_options');
    if (!$vol && !$admin) return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_style('kop-volunteer-review', $uri . '/css/volunteer-review.css', array(), filemtime($dir . '/css/volunteer-review.css'));
    wp_enqueue_script('kop-volunteer-review', $uri . '/js/volunteer-review.js', array(), filemtime($dir . '/js/volunteer-review.js'), true);
    wp_localize_script('kop-volunteer-review', 'kopVolunteer', array(
        'rest'    => esc_url_raw(rest_url('kop/v1/volunteer/')),
        'page'    => $vol ? kop_vol_csrf($vol['token']) : '',
        'nonce'   => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
        'name'    => $vol ? (string) $vol['name'] : '',
        'preview' => $admin,
    ));
}, 20);

/* ---- KOP Tools > Volunteer Reviewers --------------------------------------------- */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Volunteer Reviewers', 'Volunteer Reviewers', 'manage_options', 'kop-volunteer-reviewers', 'kop_vol_admin_page');
}, 22);

function kop_vol_admin_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    kop_vol_ensure_tables();
    $notice = '';
    $error = '';
    $link = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_vol_do'])) {
        check_admin_referer('kop_vol_admin');
        $do = sanitize_key($_POST['kop_vol_do']);
        $id = (int) ($_POST['kop_vol_id'] ?? 0);
        try {
            if ($do === 'create') {
                $made = kop_vol_create(wp_unslash((string) ($_POST['kop_vol_name'] ?? '')), wp_unslash((string) ($_POST['kop_vol_note'] ?? '')));
                $link = $made[1];
                $notice = 'Link made. Send it to them privately; it is shown only this once.';
            } elseif ($do === 'newlink') {
                $link = kop_vol_new_link($id);
                $notice = 'New link made; the old one stopped working. It is shown only this once.';
            } elseif ($do === 'revoke') {
                kop_vol_revoke($id);
                $notice = 'Turned off. Their link no longer works; "New link" turns them back on.';
            } elseif ($do === 'rename') {
                kop_vol_rename($id, wp_unslash((string) ($_POST['kop_vol_name'] ?? '')));
                $notice = 'Renamed.';
            } elseif ($do === 'queues') {
                $picked = array_map('sanitize_key', (array) ($_POST['kop_vol_sources'] ?? array()));
                update_option('kop_volunteer_sources', array_values(array_diff($picked, kop_vol_never_sources())), false);
                $notice = 'Queues saved.';
            } elseif ($do === 'dismiss') {
                kop_vol_resolve(sanitize_key((string) ($_POST['kop_vol_source'] ?? '')), (string) wp_unslash($_POST['kop_vol_key'] ?? ''), 'dismissed', wp_get_current_user()->user_login);
                $notice = 'Dismissed.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    $vols = kop_vol_list();
    $all = kop_rinbox_sources();
    $chosen = array_keys(kop_vol_sources());
    $inbox = function_exists('kop_find_template_page_url') ? (string) kop_find_template_page_url('page-admin-submissions.php') : '';
    $nonce = wp_nonce_field('kop_vol_admin', '_wpnonce', true, false);
    $btn = function ($do, $id, $label, $class = 'button') use ($nonce) {
        return '<form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="kop_vol_do" value="' . esc_attr($do) . '">'
            . '<input type="hidden" name="kop_vol_id" value="' . (int) $id . '"><button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form> ';
    };
    ?>
    <div class="wrap">
        <h1>Volunteer Reviewers</h1>
        <p style="max-width:760px">Volunteers help with the <a href="<?php echo esc_url($inbox); ?>">Review Inbox</a> through a personal link: no account, no password.
            They see the queues ticked below and can only <strong>recommend</strong> approve or reject, with a note. Nothing on the site changes until you act on the item;
            the inbox shows what they said on each card and lists them under <em>Volunteers recommend</em>. To see what they see, open
            <a href="<?php echo esc_url(kop_vol_page_url()); ?>" target="_blank" rel="noopener">the volunteer page</a> while signed in.</p>
        <?php if ($notice) : ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
        <?php if ($error) : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
        <?php if ($link) : ?>
            <div class="notice notice-info" style="padding:12px">
                <p><strong>Their link:</strong></p>
                <p><input type="text" readonly value="<?php echo esc_attr($link); ?>" style="width:100%;max-width:760px;font-family:monospace" onclick="this.select()">
                    <button type="button" class="button" onclick="var i=this.previousElementSibling;i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='Copied'">Copy</button></p>
                <p>Anyone with this link can recommend under this name, so send it only to them (a direct message, not a group chat).</p>
            </div>
        <?php endif; ?>

        <h2>Invite someone</h2>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <?php echo $nonce; ?><input type="hidden" name="kop_vol_do" value="create">
            <label>Name (shown to you, and to them on the page)<br><input type="text" name="kop_vol_name" required maxlength="100" style="width:240px"></label>
            <label>Note for you (how you know them)<br><input type="text" name="kop_vol_note" maxlength="255" style="width:320px"></label>
            <button type="submit" class="button button-primary">Make a link</button>
        </form>

        <h2>Volunteers</h2>
        <?php if (!$vols) : ?><p>Nobody yet.</p><?php else : ?>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr><th>Name</th><th>Note</th><th>Recommended</th><th>Agreed with the admin</th><th>Last seen</th><th>Link made</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($vols as $v) :
                $off = !empty($v['revoked_at']);
                $rate = (int) $v['decided'] ? round(100 * (int) $v['agreed'] / (int) $v['decided']) . '% (' . (int) $v['agreed'] . ' of ' . (int) $v['decided'] . ')' : 'none decided yet';
                ?>
                <tr<?php echo $off ? ' style="opacity:.65"' : ''; ?>>
                    <td><form method="post" style="display:flex;gap:4px"><?php echo $nonce; ?><input type="hidden" name="kop_vol_do" value="rename">
                        <input type="hidden" name="kop_vol_id" value="<?php echo (int) $v['id']; ?>">
                        <input type="text" name="kop_vol_name" value="<?php echo esc_attr($v['name']); ?>" style="width:150px" aria-label="Name">
                        <button type="submit" class="button-link">Rename</button></form>
                        <?php echo $off ? '<em>Turned off</em>' : ''; ?></td>
                    <td><?php echo esc_html($v['note']); ?></td>
                    <td><?php echo (int) $v['recs']; ?></td>
                    <td><?php echo esc_html($rate); ?></td>
                    <td><?php echo $v['last_seen'] ? esc_html(get_date_from_gmt($v['last_seen'], 'M j, Y g:i a')) : 'never opened'; ?></td>
                    <td><?php echo esc_html(get_date_from_gmt($v['link_at'], 'M j, Y')); ?></td>
                    <td><?php echo $btn('newlink', $v['id'], $off ? 'Turn on with a new link' : 'New link');
                        if (!$off) echo $btn('revoke', $v['id'], 'Turn off', 'button button-link-delete'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h2>Queues volunteers see</h2>
        <form method="post">
            <?php echo $nonce; ?><input type="hidden" name="kop_vol_do" value="queues">
            <p style="max-width:760px">Every queue lists what it holds; volunteers only recommend, so a wrong call costs you a second look, not a change on the site.
                Greyed-out queues hold readers' contact details or unpublished survivor material and are never shown to volunteers.</p>
            <div style="columns:3 220px;max-width:900px">
            <?php foreach ($all as $key => $src) :
                $never = in_array($key, kop_vol_never_sources(), true); ?>
                <label style="display:block;margin:2px 0<?php echo $never ? ';opacity:.5' : ''; ?>">
                    <input type="checkbox" name="kop_vol_sources[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $chosen, true)); disabled($never); ?>>
                    <?php echo esc_html($src['label']); ?></label>
            <?php endforeach; ?>
            </div>
            <p><button type="submit" class="button">Save queues</button></p>
        </form>

        <h2>Recent recommendations</h2>
        <?php kop_vol_admin_recent($inbox, $nonce); ?>
    </div>
    <?php
}

function kop_vol_admin_recent($inbox, $nonce) {
    global $wpdb;
    $rows = $wpdb->get_results('SELECT r.*, v.name FROM ' . kop_vol_recs_table() . ' r JOIN ' . kop_vol_table() . ' v ON v.id = r.volunteer_id ORDER BY r.updated_at DESC LIMIT 100', ARRAY_A);
    if (!$rows) {
        echo '<p>None yet.</p>';
        return;
    }
    $sources = kop_rinbox_sources();
    $labels = kop_vol_verdicts();
    $outcomes = array('' => 'Waiting for you', 'approve' => 'You approved', 'reject' => 'You rejected', 'gone' => 'Handled elsewhere', 'dismissed' => 'Dismissed');
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>When</th><th>Who</th><th>Queue</th><th>Item</th><th>Said</th><th>Note</th><th>Outcome</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $agree = in_array($r['outcome'], array('approve', 'reject'), true) && in_array($r['verdict'], array('approve', 'reject'), true)
            ? ($r['outcome'] === $r['verdict'] ? ' (agreed)' : ' (differed)') : '';
        $url = $inbox !== '' ? add_query_arg('type', $r['outcome'] === '' ? '_volunteers' : $r['source'], $inbox) : '';
        echo '<tr><td>' . esc_html(get_date_from_gmt($r['updated_at'], 'M j g:i a')) . '</td><td>' . esc_html($r['name']) . '</td><td>'
            . esc_html($sources[$r['source']]['label'] ?? $r['source']) . '</td><td>' . ($url ? '<a href="' . esc_url($url) . '">' . esc_html($r['title'] ?: $r['item_key']) . '</a>' : esc_html($r['title']))
            . '</td><td>' . esc_html($labels[$r['verdict']] ?? $r['verdict']) . '</td><td>' . esc_html($r['note']) . '</td><td>'
            . esc_html(($outcomes[$r['outcome']] ?? $r['outcome']) . $agree);
        if ($r['outcome'] === '') {
            echo ' <form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="kop_vol_do" value="dismiss">'
                . '<input type="hidden" name="kop_vol_source" value="' . esc_attr($r['source']) . '"><input type="hidden" name="kop_vol_key" value="' . esc_attr($r['item_key']) . '">'
                . '<button type="submit" class="button-link">Dismiss</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}
