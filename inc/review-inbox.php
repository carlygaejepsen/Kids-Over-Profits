<?php
/**
 * One review inbox for every queue that waits on a person.
 *
 * The Submissions Review page (templates/page-admin-submissions.php) lists
 * news, data, wiki, lawsuits and legislation with its own screen. Every other
 * queue (closure reports, facilities from news, Drive Docs, Fornits, Woodbury,
 * inspection highlights, merges, map years, ...) is a source registered here,
 * drawn on the same page by js/review-inbox.js with the same controls: edit
 * the name and details, change the category, tags, move it to another queue,
 * fill empty fields with AI, and the queue's own actions (with its Undo).
 *
 * A source is an adapter in inc/review-inbox/<name>.php that registers with
 * kop_rinbox_register('<name>', fn () => spec or null). The spec is built on
 * first use, when every queue's file has loaded (null: that queue is not
 * installed here). Other code can add or change sources through the
 * 'kop_review_inbox_sources' filter. It calls the queue's own functions
 * (kop_closure_apply(), kop_gdl_apply(), ...), so approving here does exactly
 * what the queue's old screen did. Shape:
 *
 *   'label'    tab name           'group'  heading the tab sits under
 *   'views'    [view => label]; the first is the waiting view
 *   'count'    fn(): int          items waiting
 *   'list'     fn(array $q): ['items' => [...], 'total' => int]
 *              $q: view, search, offset, limit
 *   'get'      fn(string $key): ?item
 *   'act'      fn(string $key, string $action, array $params): ['message' => ...]
 *              (throws on failure)
 *   'save'     fn(string $key, array $fields): ['message' => ...]   optional
 *   'ai_fill'  fn(string $key, string $text): ['filled' => [...]]   optional;
 *              without it the generic filler reads the item's link and fills
 *              its empty editable fields through save. $text is what the
 *              reviewer pasted in "Paste text for the AI", read instead of the link
 *   'tags_get' / 'tags_set'  fn(key) / fn(key, tags)   optional; else the
 *              shared {prefix}kop_review_tags table
 *   'origins'  fn(array $q): [{key, label, count}]   optional; the "Came from"
 *              filter (scraper, import, extension, people...); 'list' then
 *              gets the chosen key as $q['origin']
 *   'tools'    [{id, label, help, style, confirm, params [field...]}]  optional;
 *              buttons over the queue that are not about one item (scan now,
 *              read now, add every sure match for a facility)
 *   'tool'     fn(string $id, array $params): ['message' => ...]
 *   'tool_url' the queue's full screen, for what this one does not do
 *   'native'   true for the five types the page already draws itself
 *   'filters'  [{name, label, options {value: label}}]  optional; more
 *              dropdowns beside the search (kind, category, importance...);
 *              'list' gets the chosen values as $q['filters'][name] (only
 *              values that are one of the options; none chosen: not set)
 *   'view_counts' fn(array $q): [view => int]   optional; shown on the view
 *              tabs, read with each page of items ($q as for 'list')
 *   'lookup'   fn(string $name, string $q): [{value, label}]  optional; a
 *              text field or param with 'lookup' => name suggests values as
 *              the reviewer types (a company "c12", a consultant...)
 *
 * An item: key, title, subtitle, url, text, created, status, status_label,
 * facility {id, name, url}, fields [{name, label, type (text, textarea, list,
 * select, number, facility, facilities (several ids), checkbox), value,
 * options {value: label}, category}], details [{label, value}] (facts shown on
 * the card), compare {heads: [..], rows: [{label, values: [..]}]} (two records
 * side by side), preview {label, url} (a PDF or page shown in the card),
 * actions [{id, label, style (approve, reject, neutral, undo), confirm,
 * params [field...]}], moves [{id, label}], links [{label, url}],
 * selected (true: the card's "select" box starts ticked, a sure match).
 * A param with 'optional' => true may be left empty: the action is still
 * offered for the selected cards, and each card sends what its own inputs
 * say at the time.
 *
 * An action may carry 'help': one sentence on what clicking it does, shown
 * under the Approve / Reject buttons so the reviewer knows what they approve.
 *
 * REST (manage_options, wp_rest nonce): kop/v1/review-inbox/{sources, items,
 * act, save, tags, ai}; inc/review-inbox-log.php adds {log, undo, hold, held,
 * preview}: Recently done with Undo, snooze / assign, and link previews.
 * Tested by scripts/test-review-inbox.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_REVIEW_INBOX_DB_VERSION = '2';

/** Add a source: $build returns its spec, or null when its queue is not loaded. */
function kop_rinbox_register($key, callable $build) {
    $GLOBALS['kop_rinbox_builders'][$key] = $build;
}

// Where "Move to" can send an item (news, lawsuits, legislation, Industry PR, a facility's website or resources).
require_once __DIR__ . '/review-destinations.php';
// Recently done (with Undo), snooze / assign, link previews.
require_once __DIR__ . '/review-inbox-log.php';

foreach (glob(__DIR__ . '/review-inbox/*.php') ?: array() as $kop_rinbox_file) {
    require_once $kop_rinbox_file;
}
unset($kop_rinbox_file);

/** Every registered source, keyed by name, in the order they were added. */
function kop_rinbox_sources($rebuild = false) {
    static $sources = null;
    if ($sources === null || $rebuild) {
        $built = array();
        foreach ((array) ($GLOBALS['kop_rinbox_builders'] ?? array()) as $key => $build) {
            $spec = call_user_func($build);
            if (is_array($spec)) $built[$key] = $spec;
        }
        $sources = array();
        foreach ((array) apply_filters('kop_review_inbox_sources', $built) as $key => $src) {
            if (!is_array($src) || empty($src['label'])) continue;
            $src['key'] = $key;
            $src += array('group' => 'Other', 'views' => array('pending' => 'Waiting'), 'native' => false, 'tool_url' => '');
            $sources[$key] = $src;
        }
    }
    return $sources;
}

function kop_rinbox_source($key) {
    $all = kop_rinbox_sources();
    if (!isset($all[$key])) {
        throw new InvalidArgumentException('Unknown queue: ' . $key);
    }
    return $all[$key];
}

/** Who is reviewing, as the queues record it. */
function kop_rinbox_reviewer() {
    $u = wp_get_current_user();
    return $u && $u->exists() ? (string) $u->user_login : 'admin';
}

/** The records DB (news, lawsuits, closure reports, facilities_v2...). */
function kop_rinbox_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('The records database is not reachable.');
    }
    return $pdo;
}

/** [value => label] options from a plain list. */
function kop_rinbox_options(array $values) {
    $out = array();
    foreach ($values as $k => $v) {
        if (is_int($k)) $out[(string) $v] = ucfirst(str_replace('_', ' ', (string) $v));
        else $out[(string) $k] = (string) $v;
    }
    return $out;
}

/** A facility reference for an item, with its name and page. */
function kop_rinbox_facility($fid, PDO $pdo = null) {
    $fid = (int) $fid;
    if ($fid <= 0) return null;
    static $cache = array();
    if (!isset($cache[$fid])) {
        $name = '';
        try {
            $pdo = $pdo ?: kop_rinbox_pdo();
            $st = $pdo->prepare('SELECT name, unique_name, city, state, country FROM facilities_v2 WHERE id = ?');
            $st->execute(array($fid));
            if ($f = $st->fetch(PDO::FETCH_ASSOC)) {
                $place = trim(implode(', ', array_filter(array($f['city'], $f['state'] ?: $f['country']))));
                $name = ($f['name'] ?: $f['unique_name']) . ($place !== '' ? ' (' . $place . ')' : '');
            }
        } catch (Throwable $e) {
            $name = '';
        }
        $cache[$fid] = array(
            'id'   => $fid,
            'name' => $name !== '' ? $name : 'facility #' . $fid,
            'url'  => function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($fid) : '',
        );
    }
    return $cache[$fid];
}

/** Text trimmed for a card. */
function kop_rinbox_excerpt($text, $max = 400) {
    $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)));
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

/* ---- Tags ----------------------------------------------------------------- */

function kop_rinbox_tags_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_review_tags';
}

function kop_rinbox_ensure_tables() {
    if (get_option('kop_review_inbox_db') === KOP_REVIEW_INBOX_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = kop_rinbox_tags_table();
    dbDelta("CREATE TABLE $t (
        source VARCHAR(40) NOT NULL,
        item_key VARCHAR(191) NOT NULL,
        tag VARCHAR(80) NOT NULL,
        created_by VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (source, item_key, tag),
        KEY tag (tag)
    ) " . $wpdb->get_charset_collate() . ';');
    foreach (kop_rinbox_log_tables_sql($wpdb->get_charset_collate()) as $sql) dbDelta($sql);
    update_option('kop_review_inbox_db', KOP_REVIEW_INBOX_DB_VERSION);
}

/** A tag as stored: trimmed, single spaces, lower case, at most 80 characters. */
function kop_rinbox_clean_tag($tag) {
    $tag = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $tag)));
    return mb_substr($tag, 0, 80);
}

function kop_rinbox_clean_tags($tags) {
    if (is_string($tags)) $tags = preg_split('/[,\n]+/', $tags);
    $out = array();
    foreach ((array) $tags as $t) {
        $t = kop_rinbox_clean_tag($t);
        if ($t !== '') $out[$t] = true;
    }
    $out = array_keys($out);
    sort($out);
    return $out;
}

/** Tags as typed: trimmed, single spaces, no repeats (any case), case kept. */
function kop_rinbox_trim_tags($tags) {
    if (is_string($tags)) $tags = preg_split('/[,\n]+/', $tags);
    $out = array();
    foreach ((array) $tags as $t) {
        $t = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $t)), 0, 80);
        if ($t !== '' && !isset($out[mb_strtolower($t)])) $out[mb_strtolower($t)] = $t;
    }
    return array_values($out);
}

/** Tags of many items of one source: [key => [tag, ...]]. */
function kop_rinbox_tags_for($source, array $keys) {
    $src = kop_rinbox_source($source);
    $out = array();
    if (!empty($src['tags_get'])) {
        foreach ($keys as $k) $out[$k] = kop_rinbox_trim_tags(call_user_func($src['tags_get'], (string) $k));
        return $out;
    }
    if (!$keys) return $out;
    kop_rinbox_ensure_tables();
    global $wpdb;
    $t = kop_rinbox_tags_table();
    $ph = implode(',', array_fill(0, count($keys), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT item_key, tag FROM $t WHERE source = %s AND item_key IN ($ph) ORDER BY tag",
        array_merge(array($source), array_map('strval', $keys))
    ), ARRAY_A);
    foreach ((array) $rows as $r) $out[$r['item_key']][] = $r['tag'];
    return $out;
}

function kop_rinbox_set_tags($source, $key, $tags) {
    $src = kop_rinbox_source($source);
    // A queue that keeps its own tags keeps their case (news tags read "Juvenile Justice").
    if (!empty($src['tags_set'])) {
        $saved = call_user_func($src['tags_set'], (string) $key, kop_rinbox_trim_tags($tags));
        return kop_rinbox_trim_tags(is_array($saved) ? $saved : call_user_func($src['tags_get'], (string) $key));
    }
    $tags = kop_rinbox_clean_tags($tags);
    kop_rinbox_ensure_tables();
    global $wpdb;
    $t = kop_rinbox_tags_table();
    $wpdb->delete($t, array('source' => $source, 'item_key' => (string) $key));
    foreach ($tags as $tag) {
        $wpdb->insert($t, array(
            'source' => $source, 'item_key' => (string) $key, 'tag' => $tag,
            'created_by' => kop_rinbox_reviewer(), 'created_at' => current_time('mysql', true),
        ));
    }
    return $tags;
}

/** Every tag in use (shared table, plus what sources with their own tags report). */
function kop_rinbox_known_tags() {
    $tags = array();
    try {
        kop_rinbox_ensure_tables();
        global $wpdb;
        foreach ((array) $wpdb->get_col('SELECT DISTINCT tag FROM ' . kop_rinbox_tags_table() . ' ORDER BY tag LIMIT 500') as $t) $tags[$t] = true;
    } catch (Throwable $e) {
        // A missing table only means no suggestions.
    }
    foreach (kop_rinbox_trim_tags((array) apply_filters('kop_review_inbox_known_tags', array())) as $t) $tags[$t] = true;
    $tags = array_keys($tags);
    sort($tags);
    return $tags;
}

/* ---- Items ---------------------------------------------------------------- */

/** Fill in what every item needs (tags, defaults) for the browser. */
function kop_rinbox_finish_items($source, array $items) {
    $keys = array();
    foreach ($items as $it) $keys[] = (string) $it['key'];
    $tags = kop_rinbox_tags_for($source, $keys);
    foreach ($items as &$it) {
        $it += array('subtitle' => '', 'url' => '', 'text' => '', 'created' => '', 'status' => '', 'status_label' => '',
            'facility' => null, 'fields' => array(), 'actions' => array(), 'moves' => array(), 'links' => array(),
            'details' => array(), 'compare' => null, 'preview' => null, 'selected' => false);
        $it['key'] = (string) $it['key'];
        $it['tags'] = $tags[$it['key']] ?? array();
        $it['hold'] = kop_rinbox_hold_info($source, $it['key']);
    }
    unset($it);
    return $items;
}

function kop_rinbox_get_item($source, $key) {
    $src = kop_rinbox_source($source);
    if (empty($src['get'])) throw new RuntimeException('This queue cannot open one item.');
    $item = call_user_func($src['get'], (string) $key);
    if (!$item) throw new RuntimeException('That item is gone (someone may have handled it).');
    $items = kop_rinbox_finish_items($source, array($item));
    return $items[0];
}

/** Waiting counts for every source, cached a minute (some count by scanning). */
function kop_rinbox_counts($fresh = false) {
    $cached = $fresh ? false : get_transient('kop_review_inbox_counts');
    if (is_array($cached)) return $cached;
    $counts = array();
    foreach (kop_rinbox_sources() as $key => $src) {
        if (empty($src['count'])) continue;
        try {
            $counts[$key] = (int) call_user_func($src['count']);
        } catch (Throwable $e) {
            $counts[$key] = null;
        }
    }
    set_transient('kop_review_inbox_counts', $counts, MINUTE_IN_SECONDS);
    return $counts;
}

function kop_rinbox_flush_counts() {
    delete_transient('kop_review_inbox_counts');
}

/* ---- AI ------------------------------------------------------------------- */

/** True when a field value is empty (blank text, empty list). */
function kop_rinbox_is_empty($v) {
    if (is_array($v)) return !array_filter($v, function ($x) { return trim((string) $x) !== ''; });
    return trim((string) $v) === '';
}

/**
 * Fill an item's empty editable fields with AI: the item's own text plus the
 * page or PDF its link points to go to kop_ai_generate_alternating() (Groq and
 * Gemini in turn), which answers JSON for those fields only. A select field
 * only takes one of its options. Saves through the source's save.
 */
function kop_rinbox_ai_fill_generic($source, $key, $text = '') {
    $src = kop_rinbox_source($source);
    if (empty($src['save'])) throw new RuntimeException('This queue has no fields to fill.');
    $item = kop_rinbox_get_item($source, $key);
    $want = array();
    foreach ($item['fields'] as $f) {
        if (in_array($f['type'] ?? 'text', array('facility', 'number'), true) || !empty($f['readonly'])) continue;
        // A name that is only the item's own address (a Drive Docs link with no label) counts as empty.
        $bare = !is_array($f['value'] ?? '') && $item['url'] !== '' && trim((string) ($f['value'] ?? '')) === $item['url'];
        if ($bare || kop_rinbox_is_empty($f['value'] ?? '')) $want[$f['name']] = $f;
    }
    if (!$want) return array('filled' => array(), 'message' => 'Every field already has something in it.');

    $context = trim($item['title'] . "\n" . $item['subtitle'] . "\n" . $item['text']);
    foreach ($item['fields'] as $f) {
        if (!kop_rinbox_is_empty($f['value'] ?? '')) {
            $v = is_array($f['value']) ? implode('; ', $f['value']) : (string) $f['value'];
            $context .= "\n" . $f['label'] . ': ' . mb_substr($v, 0, 600);
        }
    }
    // Pasted text stands in for the link (a Drive file or paywall the reader cannot open).
    $page = trim((string) $text);
    if ($page !== '') {
        $context .= "\n\nText the reviewer pasted:\n" . mb_substr($page, 0, 12000);
    } elseif ($item['url'] !== '' && function_exists('kop_rinbox_load_enrich') && kop_rinbox_load_enrich()) {
        try {
            $page = (string) kop_enrich_document_text($item['url']);
        } catch (Throwable $e) {
            $page = '';
        }
        if ($page !== '') $context .= "\n\nText of " . $item['url'] . ":\n" . mb_substr($page, 0, 12000);
    }

    $spec = array();
    foreach ($want as $name => $f) {
        $line = '- "' . $name . '": ' . $f['label'];
        $type = $f['type'] ?? 'text';
        if ($type === 'select' && !empty($f['options'])) {
            $line .= ' (exactly one of: ' . implode(', ', array_map('strval', array_keys($f['options']))) . ')';
        } elseif ($type === 'list') {
            $line .= ' (a JSON list of short strings)';
        } elseif ($type === 'textarea') {
            $line .= ' (a few plain sentences)';
        }
        $spec[] = $line;
    }
    $prompt = "You fill in missing fields of a record for Kids Over Profits, a site documenting abuse in the troubled teen industry.\n"
        . "Use only facts stated in the material below. Never guess: leave a field out when the material does not say it.\n"
        . "Write neutrally and factually. Never blame a young person for what was done to them.\n"
        . "Answer with one JSON object and nothing else, with only these keys:\n" . implode("\n", $spec)
        . "\n\nMaterial:\n" . $context;

    kop_rinbox_load_ai();
    $raw = kop_ai_generate_alternating($prompt, array('maxTokens' => 1500, 'temperature' => 0.1));
    $answer = kop_ai_extract_json($raw);
    if (!is_array($answer)) throw new RuntimeException('The AI answer was not readable. Try again.');

    $fields = array();
    foreach ($want as $name => $f) {
        if (!array_key_exists($name, $answer)) continue;
        $v = $answer[$name];
        $type = $f['type'] ?? 'text';
        if ($type === 'select') {
            $v = (string) $v;
            if (empty($f['options']) || !array_key_exists($v, $f['options'])) continue;
        } elseif ($type === 'list') {
            $v = array_values(array_filter(array_map(function ($x) { return trim((string) $x); }, is_array($v) ? $v : preg_split('/[\n;]+/', (string) $v)), 'strlen'));
        } else {
            $v = is_array($v) ? implode('; ', $v) : trim((string) $v);
        }
        if (kop_rinbox_is_empty($v)) continue;
        $fields[$name] = $v;
    }
    if (!$fields) return array('filled' => array(), 'message' => 'The AI found nothing to add from this item and its link.');
    call_user_func($src['save'], (string) $key, $fields);
    return array('filled' => array_keys($fields), 'message' => 'Filled: ' . implode(', ', array_map(function ($n) use ($want) { return $want[$n]['label']; }, array_keys($fields))) . '.');
}

function kop_rinbox_load_ai() {
    // kop_seed_pdo() loads api/config.php, which reads the AI keys from .env.
    kop_rinbox_pdo();
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
}

function kop_rinbox_load_enrich() {
    if (!function_exists('kop_enrich_document_text')) {
        $path = get_stylesheet_directory() . '/api/lib-record-enrich.php';
        if (!file_exists($path)) return false;
        require_once $path;
    }
    return true;
}

/* ---- REST ----------------------------------------------------------------- */

add_action('rest_api_init', function () {
    $perm = function () { return current_user_can('manage_options'); };
    $routes = array(
        'sources' => array('GET', 'kop_rinbox_rest_sources'),
        'items'   => array('GET', 'kop_rinbox_rest_items'),
        'item'    => array('GET', 'kop_rinbox_rest_item'),
        'origins' => array('GET', 'kop_rinbox_rest_origins'),
        'act'     => array('POST', 'kop_rinbox_rest_act'),
        'save'    => array('POST', 'kop_rinbox_rest_save'),
        'tags'    => array('POST', 'kop_rinbox_rest_tags'),
        'ai'      => array('POST', 'kop_rinbox_rest_ai'),
        'tool'    => array('POST', 'kop_rinbox_rest_tool'),
        'lookup'  => array('GET', 'kop_rinbox_rest_lookup'),
    );
    foreach ($routes as $path => $r) {
        register_rest_route('kop/v1', '/review-inbox/' . $path, array(
            'methods' => $r[0], 'callback' => $r[1], 'permission_callback' => $perm,
        ));
    }
});

/** Run a REST handler, turning a thrown error into a 400 with its message. */
function kop_rinbox_rest(callable $fn) {
    try {
        return new WP_REST_Response(call_user_func($fn), 200);
    } catch (Throwable $e) {
        return new WP_REST_Response(array('error' => $e->getMessage()), 400);
    }
}

function kop_rinbox_rest_sources(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $counts = kop_rinbox_counts((bool) $req->get_param('fresh'));
        $out = array();
        foreach (kop_rinbox_sources() as $key => $src) {
            // Snoozed items and items handed to someone else are not waiting on me.
            $count = $counts[$key] ?? null;
            if ($count !== null) $count = max(0, $count - count(kop_rinbox_hidden_keys($key)));
            $out[] = array(
                'key' => $key, 'label' => $src['label'], 'group' => $src['group'], 'views' => $src['views'],
                'count' => $count, 'native' => (bool) $src['native'], 'tool_url' => (string) $src['tool_url'],
                'help' => (string) ($src['help'] ?? ''), 'can_save' => !empty($src['save']),
                'can_ai' => !empty($src['save']) || !empty($src['ai_fill']),
                'has_origins' => !empty($src['origins']),
                'tools' => array_values((array) ($src['tools'] ?? array())),
                'filters' => array_values((array) ($src['filters'] ?? array())),
            );
        }
        return array('sources' => $out, 'tags' => kop_rinbox_known_tags(), 'admins' => kop_rinbox_admins(),
            'me' => get_current_user_id(), 'held' => kop_rinbox_held_counts());
    });
}

function kop_rinbox_rest_items(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $source = (string) $req->get_param('source');
        $src = kop_rinbox_source($source);
        $views = array_keys($src['views']);
        $view = (string) $req->get_param('view');
        if (!in_array($view, $views, true)) $view = $views[0];
        $q = array(
            'view'   => $view,
            'search' => trim((string) $req->get_param('search')),
            'offset' => max(0, (int) $req->get_param('offset')),
            'limit'  => max(1, min(100, (int) ($req->get_param('limit') ?: 25))),
            'origin' => sanitize_key((string) $req->get_param('origin')),
            'filters' => kop_rinbox_chosen_filters($src, function ($name) use ($req) { return $req->get_param('f_' . $name); }),
        );
        $keys = $req->get_param('keys');
        if ($keys !== null && $keys !== '') {
            // Specific items (the page's own cards ask for theirs by id).
            $items = array();
            foreach (array_slice(array_filter(array_map('strval', is_array($keys) ? $keys : explode(',', (string) $keys)), 'strlen'), 0, 100) as $k) {
                $it = !empty($src['get']) ? call_user_func($src['get'], $k) : null;
                if ($it) $items[] = $it;
            }
            return array('items' => kop_rinbox_finish_items($source, $items), 'total' => count($items), 'view' => $view);
        }
        // The waiting view leaves out what is snoozed or handed to someone else.
        if ($view === $views[0]) {
            $res = kop_rinbox_list_unheld($src, $source, $q);
        } else {
            $res = call_user_func($src['list'], $q);
            $res['next_offset'] = $q['offset'] + count((array) ($res['items'] ?? array()));
        }
        $out = array('items' => kop_rinbox_finish_items($source, (array) ($res['items'] ?? array())), 'total' => (int) ($res['total'] ?? 0), 'view' => $view,
            'next_offset' => (int) $res['next_offset'], 'held' => (int) ($res['held'] ?? 0));
        if (!empty($src['view_counts'])) {
            try {
                $out['view_counts'] = array_map('intval', (array) call_user_func($src['view_counts'], $q));
            } catch (Throwable $e) {
                // The tabs just show no counts.
            }
        }
        return $out;
    });
}

/** The source's 'filters' values from a request: [name => value], only values that are one of its options. */
function kop_rinbox_chosen_filters(array $src, callable $param) {
    $out = array();
    foreach ((array) ($src['filters'] ?? array()) as $f) {
        $name = (string) ($f['name'] ?? '');
        $v = $name !== '' ? (string) call_user_func($param, $name) : '';
        if ($v !== '' && array_key_exists($v, (array) ($f['options'] ?? array()))) $out[$name] = $v;
    }
    return $out;
}

/** Suggestions for a field with 'lookup': [{value, label}], at most 20. */
function kop_rinbox_rest_lookup(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $src = kop_rinbox_source((string) $req->get_param('source'));
        $q = trim((string) $req->get_param('q'));
        if (empty($src['lookup']) || mb_strlen($q) < 2) return array('options' => array());
        $out = array();
        foreach ((array) call_user_func($src['lookup'], sanitize_key((string) $req->get_param('name')), $q) as $o) {
            if (isset($o['value']) && (string) $o['value'] !== '') {
                $out[] = array('value' => (string) $o['value'], 'label' => (string) ($o['label'] ?? $o['value']));
            }
        }
        return array('options' => array_slice($out, 0, 20));
    });
}

/** "Came from" choices with their counts: [{key, label, count}] (empty when the source has none). */
function kop_rinbox_rest_origins(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $src = kop_rinbox_source((string) $req->get_param('source'));
        if (empty($src['origins'])) return array('origins' => array());
        $views = array_keys($src['views']);
        $view = (string) $req->get_param('view');
        return array('origins' => array_values((array) call_user_func($src['origins'], array(
            'view' => in_array($view, $views, true) ? $view : $views[0],
            'status' => sanitize_key((string) $req->get_param('status')),
        ))));
    });
}

function kop_rinbox_rest_item(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        return array('item' => kop_rinbox_get_item((string) $req->get_param('source'), (string) $req->get_param('key')));
    });
}

/** The item again after a change, or null when it left the source. */
function kop_rinbox_after($source, $key) {
    try {
        return kop_rinbox_get_item($source, $key);
    } catch (Throwable $e) {
        return null;
    }
}

function kop_rinbox_rest_act(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $source = (string) $req->get_param('source');
        $key = (string) $req->get_param('key');
        $src = kop_rinbox_source($source);
        $params = $req->get_param('params');
        $params = is_array($params) ? wp_unslash($params) : array();
        $action = sanitize_key((string) $req->get_param('action'));
        $before = kop_rinbox_after($source, $key);
        $res = call_user_func($src['act'], $key, $action, $params);
        kop_rinbox_flush_counts();
        $res = is_array($res) ? $res : array();
        $res += array('message' => 'Done.', 'item' => kop_rinbox_after($source, $res['key'] ?? $key));
        kop_rinbox_log_action($source, (string) ($res['key'] ?? $key), $action, $params, $before, $res['item'], $res['message'], $res['undo'] ?? null);
        unset($res['undo']);
        return $res;
    });
}

function kop_rinbox_rest_tool(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $src = kop_rinbox_source((string) $req->get_param('source'));
        if (empty($src['tool'])) throw new RuntimeException('This queue has no tools.');
        $params = $req->get_param('params');
        $res = call_user_func($src['tool'], sanitize_key((string) $req->get_param('tool')), is_array($params) ? wp_unslash($params) : array());
        kop_rinbox_flush_counts();
        return (is_array($res) ? $res : array()) + array('message' => 'Done.');
    });
}

function kop_rinbox_rest_save(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $source = (string) $req->get_param('source');
        $key = (string) $req->get_param('key');
        $src = kop_rinbox_source($source);
        if (empty($src['save'])) throw new RuntimeException('This queue has no editable fields.');
        $fields = $req->get_param('fields');
        $res = call_user_func($src['save'], $key, is_array($fields) ? wp_unslash($fields) : array());
        $res = is_array($res) ? $res : array();
        return $res + array('message' => 'Saved.', 'item' => kop_rinbox_after($source, $key));
    });
}

function kop_rinbox_rest_tags(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $source = (string) $req->get_param('source');
        $tags = kop_rinbox_set_tags($source, (string) $req->get_param('key'), wp_unslash((array) $req->get_param('tags')));
        return array('tags' => $tags, 'message' => $tags ? 'Tags saved.' : 'Tags cleared.');
    });
}

function kop_rinbox_rest_ai(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $source = (string) $req->get_param('source');
        $key = (string) $req->get_param('key');
        $src = kop_rinbox_source($source);
        $text = mb_substr(trim((string) $req->get_param('text')), 0, 60000);
        $res = !empty($src['ai_fill']) ? call_user_func($src['ai_fill'], $key, $text) : kop_rinbox_ai_fill_generic($source, $key, $text);
        $res = is_array($res) ? $res : array();
        return $res + array('message' => 'Done.', 'item' => kop_rinbox_after($source, $key));
    });
}

/* ---- Page assets ---------------------------------------------------------- */

add_action('wp_enqueue_scripts', function () {
    if (!is_page_template('page-admin-submissions.php') && !is_page_template('templates/page-admin-submissions.php')) return;
    if (!current_user_can('manage_options')) return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_style('kop-review-inbox', $uri . '/css/review-inbox.css', array('kop-admin-submissions'), filemtime($dir . '/css/review-inbox.css'));
    wp_enqueue_script('kop-review-inbox', $uri . '/js/review-inbox.js', array('kop-admin-submissions'), filemtime($dir . '/js/review-inbox.js'), true);
    wp_localize_script('kop-review-inbox', 'kopReviewInbox', array(
        'rest'  => esc_url_raw(rest_url('kop/v1/review-inbox/')),
        'nonce' => wp_create_nonce('wp_rest'),
    ));
}, 20);
