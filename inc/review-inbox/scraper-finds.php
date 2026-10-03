<?php
/**
 * Review inbox source: what the nightly news scraper found and turned away
 * (scripts/discover-articles.php writes scripts/.discovery-rejected.json on
 * the server: link, title, origin, host, reason, meta). Nobody could see these
 * before; a good article the filter missed can now be sent where it belongs
 * ("Move to" News, Lawsuits, Legislation, Industry PR, a facility's website
 * or resources), or dismissed. Decisions live in the option
 * kop_scraper_finds_decisions, keyed by the link's hash; the log itself is
 * only read.
 *
 * A Google News link is resolved to the publisher's address when it is sent
 * (one request per click, so the server is not rate-limited the way the
 * nightly sweep is). When Google will not say, "Edit details" takes the
 * article's own address. Sent articles are submitted as "Scraper finds
 * import", so the hourly enrich (api/lib-record-enrich.php) reads them and
 * fills their author, date, summary and facilities like any import.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_SCRAPER_FINDS_OPTION = 'kop_scraper_finds_decisions';

kop_rinbox_register('scraper-finds', function () {
    if (!function_exists('kop_rdest_put')) return null;
    return array(
        'label'    => 'Scraper finds',
        'group'    => 'Found by the news scans',
        'help'     => 'Articles the nightly news scraper found but turned away. Send a good one where it belongs with "Move to", or dismiss it. '
            . '"Passed, link not found" are articles the filter accepted but whose Google News link could not be opened that night.',
        'views'    => array(
            'waiting'    => 'Turned away',
            'unresolved' => 'Passed, link not found',
            'blocked'    => 'Blocked sites and repeats',
            'sent'       => 'Sent',
            'dismissed'  => 'Dismissed',
        ),
        'count'    => function () {
            $n = 0;
            $dec = kop_scraper_finds_decisions();
            foreach (kop_scraper_finds_entries() as $e) {
                if (!isset($dec[$e['key']]) && kop_scraper_finds_view_of($e) !== 'blocked') $n++;
            }
            return $n;
        },
        'list'     => 'kop_scraper_finds_list',
        'get'      => function ($key) {
            $e = kop_scraper_finds_entry($key);
            return $e ? kop_scraper_finds_item($e) : null;
        },
        'act'      => 'kop_scraper_finds_act',
        'save'     => 'kop_scraper_finds_save',
        'ai_fill'  => function () {
            return array('filled' => array(), 'message' => 'Send it to News first; the news queue reads the article and fills its details (or use its own "Fill empty fields with AI").');
        },
        'origins'  => 'kop_scraper_finds_origins',
    );
});

function kop_scraper_finds_file() {
    // KOP_SCRAPER_FINDS_FILE: a fixture log for scripts/review-inbox-tests/scraper-finds.php.
    return defined('KOP_SCRAPER_FINDS_FILE') ? KOP_SCRAPER_FINDS_FILE : get_stylesheet_directory() . '/scripts/.discovery-rejected.json';
}

/** Every logged find, newest first, with a stable key. Cached per request. */
function kop_scraper_finds_entries() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = array();
    $file = kop_scraper_finds_file();
    $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
    foreach ((array) ($data['entries'] ?? array()) as $e) {
        if (!is_array($e) || empty($e['link'])) continue;
        $e['key'] = substr(sha1((string) $e['link']), 0, 16);
        $cache[] = $e;
    }
    return $cache;
}

function kop_scraper_finds_entry($key) {
    foreach (kop_scraper_finds_entries() as $e) if ($e['key'] === (string) $key) return $e;
    return null;
}

function kop_scraper_finds_decisions() {
    $d = get_option(KOP_SCRAPER_FINDS_OPTION, array());
    return is_array($d) ? $d : array();
}

function kop_scraper_finds_set_decision($key, $decision) {
    $all = kop_scraper_finds_decisions();
    if ($decision === null) unset($all[$key]);
    else $all[$key] = $decision;
    update_option(KOP_SCRAPER_FINDS_OPTION, $all, false);
}

/** Which view an undecided find belongs in. */
function kop_scraper_finds_view_of(array $e) {
    $reason = (string) ($e['reason'] ?? '');
    if ($reason === 'gn-resolution-failed') return 'unresolved';
    if (in_array($reason, array('blacklist-host', 'blacklist-path', 'blacklist-path-post-resolve', 'duplicate-after-resolution',
        'facility-own-website', 'facility-own-website-inferred', 'pdf-document', 'invalid-url', 'homepage-url'), true)) return 'blocked';
    return 'waiting';
}

function kop_scraper_finds_origin_labels() {
    return array(
        'google-news-topic' => 'Google News: topic searches',
        'google-news'       => 'Google News: facility searches',
        'reddit-link'       => 'Reddit: link posts',
        'reddit-selftext'   => 'Reddit: links in posts',
        ''                  => 'Google News: facility sweep',
    );
}

function kop_scraper_finds_reason_label($reason) {
    $labels = array(
        'topic-unmatched'              => 'A topic search hit, but its phrase is not in the headline',
        'low-score'                    => 'Scored too low',
        'facility-unmatched'           => 'No facility named',
        'gn-resolution-failed'         => 'Passed, but the Google News link could not be opened',
        'blacklist-host'               => 'Site on the block list',
        'state-mismatch'               => 'Facility name matched, but a different state',
        'pdf-document'                 => 'A PDF',
        'duplicate-after-resolution'   => 'Already submitted',
        'facility-own-website-inferred' => 'The program\'s own website',
        'facility-own-website'         => 'The program\'s own website',
        'generic-alias-unconfirmed'    => 'A common-word name without its town',
    );
    return $labels[$reason] ?? str_replace('-', ' ', (string) $reason);
}

/** The finds in a view, newest first, before paging. */
function kop_scraper_finds_in_view(array $q, $ignore_origin = false) {
    $dec = kop_scraper_finds_decisions();
    $search = mb_strtolower(trim((string) ($q['search'] ?? '')));
    $out = array();
    foreach (kop_scraper_finds_entries() as $e) {
        $d = $dec[$e['key']] ?? null;
        $view = $d ? ($d['decision'] === 'sent' ? 'sent' : 'dismissed') : kop_scraper_finds_view_of($e);
        if ($view !== $q['view']) continue;
        if (!$ignore_origin && !empty($q['origin']) && (string) ($e['origin'] ?? '') !== ($q['origin'] === 'sweep' ? '' : $q['origin'])) continue;
        if ($search !== '' && mb_strpos(mb_strtolower(($e['title'] ?? '') . ' ' . ($e['host'] ?? '') . ' ' . ($e['facilityQuery'] ?? '')), $search) === false) continue;
        $out[] = $e;
    }
    return $out;
}

function kop_scraper_finds_list(array $q) {
    $all = kop_scraper_finds_in_view($q);
    return array('items' => array_map('kop_scraper_finds_item', array_slice($all, (int) $q['offset'], (int) $q['limit'])), 'total' => count($all));
}

/** "Came from": each scraper origin with its count in the view ('sweep' for the facility sweep, logged with no origin). */
function kop_scraper_finds_origins(array $q) {
    $counts = array();
    foreach (kop_scraper_finds_in_view($q + array('search' => ''), true) as $e) {
        $o = (string) ($e['origin'] ?? '');
        $counts[$o] = ($counts[$o] ?? 0) + 1;
    }
    $out = array();
    foreach (kop_scraper_finds_origin_labels() as $o => $label) {
        if (!empty($counts[$o])) $out[] = array('key' => $o === '' ? 'sweep' : $o, 'label' => $label, 'count' => $counts[$o]);
    }
    return $out;
}

function kop_scraper_finds_item(array $e) {
    $e = kop_scraper_finds_with_edits($e);
    $d = kop_scraper_finds_decisions()[$e['key']] ?? null;
    $labels = kop_scraper_finds_origin_labels();
    $meta = is_array($e['meta'] ?? null) ? $e['meta'] : array();
    $why = array(kop_scraper_finds_reason_label($e['reason'] ?? ''));
    if (!empty($e['facilityQuery'])) $why[] = 'Searched for: ' . $e['facilityQuery'];
    if (!empty($e['topicQuery'])) $why[] = 'Topic: ' . str_replace('-', ' ', (string) $e['topicQuery']);
    if (isset($meta['score'])) $why[] = 'Score ' . (int) $meta['score'] . (isset($meta['threshold']) ? ' of ' . (int) $meta['threshold'] . ' needed' : '');
    if (!empty($meta['reasons']) && is_array($meta['reasons'])) $why[] = 'Matched: ' . implode(', ', array_slice(array_map('strval', $meta['reasons']), 0, 6));

    $url = (string) (($d['url'] ?? '') !== '' ? $d['url'] : ($e['own_url'] !== '' ? $e['own_url'] : $e['link']));
    $actions = array();
    $moves = array();
    if (!$d) {
        $actions[] = array('id' => 'dismiss', 'label' => 'Dismiss', 'style' => 'reject');
        $moves = kop_rdest_moves();
    } elseif ($d['decision'] === 'sent') {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo');
        $why[] = (string) ($d['message'] ?? 'Sent.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => 'Back to the list', 'style' => 'neutral');
    }
    $title = trim((string) ($d['title'] ?? '') !== '' ? $d['title'] : ($e['title'] ?? ''));
    return array(
        'key'          => $e['key'],
        'title'        => $title !== '' ? $title : (string) ($e['host'] ?? $e['link']),
        'subtitle'     => implode(' · ', array_filter(array($labels[(string) ($e['origin'] ?? '')] ?? (string) $e['origin'], (string) ($e['host'] ?? '')))),
        'url'          => $url,
        'text'         => implode("\n", $why),
        'created'      => str_replace(array('T', 'Z'), array(' ', ''), (string) ($e['ts'] ?? '')),
        'status'       => $d ? $d['decision'] : 'waiting',
        'status_label' => $d ? ($d['decision'] === 'sent' ? 'Sent' : 'Dismissed') : 'Turned away',
        'fields'       => array(
            array('name' => 'title', 'label' => 'Headline', 'type' => 'text', 'value' => $title, 'readonly' => (bool) $d),
            array('name' => 'url', 'label' => 'Article\'s own address (if the Google News link will not open)', 'type' => 'text',
                'value' => (string) ($d['url'] ?? $e['own_url']), 'readonly' => (bool) ($d && $d['decision'] === 'sent')),
        ),
        'actions'      => $actions,
        'moves'        => $moves,
    );
}

/** Keep an edited headline or address on an undecided find (stored with a 'draft' decision that is not a decision). */
function kop_scraper_finds_save($key, array $fields) {
    $e = kop_scraper_finds_entry($key);
    if (!$e) throw new RuntimeException('That find is no longer in the scraper\'s log.');
    $all = kop_scraper_finds_decisions();
    $d = $all[$key] ?? null;
    if ($d && $d['decision'] === 'sent') throw new RuntimeException('Undo the send before editing it.');
    $edits = get_option('kop_scraper_finds_edits', array());
    $edits = is_array($edits) ? $edits : array();
    $cur = $edits[$key] ?? array();
    if (array_key_exists('title', $fields)) $cur['title'] = trim(preg_replace('/\s+/u', ' ', (string) $fields['title']));
    if (array_key_exists('url', $fields)) {
        $u = trim((string) $fields['url']);
        if ($u !== '' && !preg_match('#^https?://#i', $u)) throw new RuntimeException('The address must start with http:// or https://.');
        $cur['url'] = $u;
    }
    $edits[$key] = $cur;
    update_option('kop_scraper_finds_edits', $edits, false);
    return array('message' => 'Saved.');
}

/** A find with its edits (headline, address) laid over the log. */
function kop_scraper_finds_with_edits(array $e) {
    $edits = get_option('kop_scraper_finds_edits', array());
    $cur = is_array($edits) ? ($edits[$e['key']] ?? array()) : array();
    if (!empty($cur['title'])) $e['title'] = $cur['title'];
    $e['own_url'] = (string) ($cur['url'] ?? '');
    return $e;
}

function kop_scraper_finds_act($key, $action, array $params) {
    $e = kop_scraper_finds_entry($key);
    if (!$e) throw new RuntimeException('That find is no longer in the scraper\'s log.');
    $e = kop_scraper_finds_with_edits($e);
    $d = kop_scraper_finds_decisions()[$key] ?? null;
    $user = kop_rinbox_reviewer();
    switch ($action) {
        case 'dismiss':
            if ($d) throw new RuntimeException('It was already handled.');
            kop_scraper_finds_set_decision($key, array('decision' => 'dismissed', 'by' => $user, 'at' => time()));
            return array('message' => 'Dismissed. "Back to the list" on the Dismissed tab brings it back.');
        case 'move':
            if ($d) throw new RuntimeException('It was already handled.');
            $url = $e['own_url'] !== '' ? $e['own_url'] : kop_scraper_finds_resolve((string) $e['link']);
            if (strpos($url, 'https://news.google.com/') === 0) {
                throw new RuntimeException('Google News would not say where this article is. Open the link, copy the article\'s address, '
                    . 'put it in "Edit details" under Article\'s own address, save, and send it again.');
            }
            $done = kop_rdest_put((string) ($params['to'] ?? ''), array(
                'url' => $url, 'title' => (string) ($e['title'] ?? ''), 'site_name' => (string) ($e['host'] ?? ''),
                'facility_id' => (int) ($params['facility_id'] ?? 0), 'kind' => (string) ($params['kind'] ?? ''),
                'source_note' => 'Found by the news scraper', 'via' => 'Scraper finds import',
                'note' => 'Found by the news scraper (' . ($e['origin'] ?: 'facility sweep') . '), turned away as "'
                    . kop_scraper_finds_reason_label($e['reason'] ?? '') . '", sent here by ' . $user . '.',
            ), $user);
            kop_scraper_finds_set_decision($key, array('decision' => 'sent', 'done' => $done, 'url' => $url,
                'title' => (string) ($e['title'] ?? ''), 'message' => $done['message'], 'by' => $user, 'at' => time()));
            return array('message' => $done['message'] . ' Undo is on the Sent tab.');
        case 'undo':
            if (!$d) throw new RuntimeException('There is nothing to undo.');
            if ($d['decision'] === 'sent') kop_rdest_take_back($d['done']);
            kop_scraper_finds_set_decision($key, null);
            return array('message' => $d['decision'] === 'sent' ? 'Undone: taken back from where it went. It is on the list again.' : 'Back on the list.');
    }
    throw new RuntimeException('Unknown action.');
}

/**
 * The publisher's address behind a news.google.com link, or the link itself
 * when Google will not say. The same two steps as resolve_google_news_url()
 * in scripts/discover-articles.php: read the article page's signature, then
 * ask batchexecute for the address.
 */
function kop_scraper_finds_resolve($url) {
    if (strpos($url, 'https://news.google.com/') !== 0 || !preg_match('#/(?:rss/)?articles/([^/?\#]+)#', $url, $tm)) return $url;
    $token = $tm[1];
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
    $page = wp_remote_get('https://news.google.com/articles/' . $token, array('timeout' => 20, 'user-agent' => $ua));
    if (is_wp_error($page) || wp_remote_retrieve_response_code($page) !== 200) return $url;
    $html = (string) wp_remote_retrieve_body($page);
    $sg = preg_match('/data-n-a-sg="([^"]+)"/', $html, $m) ? $m[1] : '';
    $ts = preg_match('/data-n-a-ts="([^"]+)"/', $html, $m) ? $m[1] : '';
    if ($sg === '' || $ts === '') return $url;
    $inner = wp_json_encode(array('garturlreq', array(
        array('X', 'X', array('X', 'X'), null, null, 1, 1, 'US:en', null, 1, null, null, null, null, null, 0, 1),
        'X', 'X', 1, array(1, 1, 1), 1, 1, null, 0, 0, null, 0,
    ), $token, is_numeric($ts) ? $ts + 0 : $ts, $sg), JSON_UNESCAPED_SLASHES);
    $freq = wp_json_encode(array(array(array('Fbv4je', $inner, null, '1'))), JSON_UNESCAPED_SLASHES);
    $res = wp_remote_post('https://news.google.com/_/DotsSplashUi/data/batchexecute?rpcids=Fbv4je&rt=c', array(
        'timeout' => 20, 'user-agent' => $ua,
        'headers' => array('Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8'),
        'body' => 'f.req=' . rawurlencode($freq),
    ));
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return $url;
    $text = (string) wp_remote_retrieve_body($res);
    if (!preg_match('/garturlres\\\\",\\\\"(https?:\/\/(?:[^\\\\"]|\\\\\\\\)+)\\\\",/', $text, $um)) return $url;
    $resolved = str_replace(array('\\\\', '\\"'), array('\\', '"'), $um[1]);
    return strpos($resolved, 'https://news.google.com/') === 0 ? $url : $resolved;
}
