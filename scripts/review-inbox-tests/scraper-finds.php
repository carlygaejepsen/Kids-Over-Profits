<?php
/**
 * scripts/test-review-inbox.php checks for the 'scraper-finds' source
 * (inc/review-inbox/scraper-finds.php): a fixture rejected log with one find
 * of each kind; views, "Came from", dismiss, send to News and to Industry PR
 * with Undo, and a Google News link that cannot be resolved.
 */

define('KOP_SCRAPER_FINDS_FILE', sys_get_temp_dir() . '/kop-scraper-finds-' . getmypid() . '.json');
file_put_contents(KOP_SCRAPER_FINDS_FILE, json_encode(array('lastUpdated' => gmdate('c'), 'entries' => array(
    array('ts' => '2026-10-03T18:33:12Z', 'link' => 'https://www.ksl.com/article/1/ranch-staff-charged-inbox-test', 'title' => 'Ranch staff charged after teen injured - KSL',
        'origin' => 'google-news-topic', 'host' => 'ksl.com', 'facilityQuery' => null, 'topicQuery' => 'troubled-teen-program', 'reason' => 'topic-unmatched',
        'meta' => array('topic' => 'troubled-teen-program', 'score' => 2, 'reasons' => array('keyword:charged'))),
    array('ts' => '2026-10-02T06:00:00Z', 'link' => 'https://news.google.com/rss/articles/CBMiTESTTOKEN?oc=5', 'title' => 'Academy closes after state review - Daily Herald',
        'origin' => 'google-news', 'host' => 'heraldextra.com', 'facilityQuery' => 'Example Academy', 'topicQuery' => null, 'reason' => 'gn-resolution-failed', 'meta' => array()),
    array('ts' => '2026-10-01T06:00:00Z', 'link' => 'https://www.reddit.com/r/troubledteens/comments/abc/test/', 'title' => 'My time there',
        'origin' => 'reddit-link', 'host' => 'reddit.com', 'reason' => 'blacklist-host', 'meta' => array()),
    array('ts' => '2026-09-30T06:00:00Z', 'link' => 'https://www.example-news.test/2026/09/30/program-marketing-gala', 'title' => 'Program holds annual gala',
        'origin' => 'reddit-selftext', 'host' => 'example-news.test', 'reason' => 'low-score', 'meta' => array('score' => 1, 'threshold' => 3)),
))));
register_shutdown_function(function () { @unlink(KOP_SCRAPER_FINDS_FILE); });

if (!class_exists('WP_Error')) {
    class WP_Error { public function get_error_message() { return 'offline'; } }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($x) { return $x instanceof WP_Error; }
}
// No network in the test: Google News never answers, so its link stays unresolved.
if (!function_exists('wp_remote_get')) {
    function wp_remote_get() { return new WP_Error(); }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post() { return new WP_Error(); }
}

function kop_rinbox_test_scraper_finds(array $src, array $item, callable $check) {
    $pdo = kop_rinbox_pdo();
    $views = array();
    foreach (array_keys($src['views']) as $v) {
        $views[$v] = array_column(call_user_func($src['list'], array('view' => $v, 'search' => '', 'offset' => 0, 'limit' => 50, 'origin' => ''))['items'], 'title');
    }
    $check('scraper-finds: turned away, passed-but-unresolved and blocked finds each have their view',
        count($views['waiting']) === 2 && count($views['unresolved']) === 1 && count($views['blocked']) === 1, json_encode($views));
    $check('scraper-finds: the count leaves out blocked sites', (int) call_user_func($src['count']) === 3);
    $origins = call_user_func($src['origins'], array('view' => 'waiting', 'status' => ''));
    $check('scraper-finds: Came from lists the origins in the view', array_column($origins, 'key') === array('google-news-topic', 'reddit-selftext'), json_encode($origins));
    $only = call_user_func($src['list'], array('view' => 'waiting', 'search' => '', 'offset' => 0, 'limit' => 50, 'origin' => 'reddit-selftext'));
    $check('scraper-finds: filtering by where it came from', $only['total'] === 1 && $only['items'][0]['title'] === 'Program holds annual gala');

    $ksl = substr(sha1('https://www.ksl.com/article/1/ranch-staff-charged-inbox-test'), 0, 16);
    $res = call_user_func($src['act'], $ksl, 'move', array('to' => 'news'));
    $d = kop_scraper_finds_decisions()[$ksl] ?? null;
    $row = $d ? $pdo->query('SELECT status, article_url, submitted_by FROM news_submissions WHERE id = ' . (int) $d['done']['id'])->fetch(PDO::FETCH_ASSOC) : null;
    $check('scraper-finds: send to News makes a waiting article the hourly enrich will read',
        $row && $row['status'] === 'submitted' && strpos($row['submitted_by'], '(Scraper finds import)') !== false, $res['message']);
    $check('scraper-finds: a sent find is on the Sent tab with Undo', kop_rinbox_get_item('scraper-finds', $ksl)['actions'][0]['id'] === 'undo');
    call_user_func($src['act'], $ksl, 'undo', array());
    $gone = $pdo->query('SELECT status FROM news_submissions WHERE id = ' . (int) $d['done']['id'])->fetchColumn();
    $check('scraper-finds: Undo takes the article back and the find waits again', $gone === 'deleted' && !isset(kop_scraper_finds_decisions()[$ksl]));

    $gala = substr(sha1('https://www.example-news.test/2026/09/30/program-marketing-gala'), 0, 16);
    call_user_func($src['act'], $gala, 'move', array('to' => 'promo'));
    $d = kop_scraper_finds_decisions()[$gala];
    $check('scraper-finds: send to Industry PR files a promotional row',
        $pdo->query('SELECT status FROM news_submissions WHERE id = ' . (int) $d['done']['id'])->fetchColumn() === 'promotional');
    call_user_func($src['act'], $gala, 'undo', array());

    $gn = substr(sha1('https://news.google.com/rss/articles/CBMiTESTTOKEN?oc=5'), 0, 16);
    try {
        call_user_func($src['act'], $gn, 'move', array('to' => 'news'));
        $check('scraper-finds: an unresolved Google News link asks for the address', false);
    } catch (RuntimeException $e) {
        $check('scraper-finds: an unresolved Google News link asks for the address', stripos($e->getMessage(), "Article's own address") !== false);
    }
    call_user_func($src['save'], $gn, array('url' => 'https://www.heraldextra.com/news/2026/10/academy-closes-inbox-test/'));
    $res = call_user_func($src['act'], $gn, 'move', array('to' => 'news'));
    $d = kop_scraper_finds_decisions()[$gn];
    $check('scraper-finds: with the address typed in, it sends', $d['url'] === 'https://www.heraldextra.com/news/2026/10/academy-closes-inbox-test/', $res['message']);
    call_user_func($src['act'], $gn, 'undo', array());

    call_user_func($src['act'], $ksl, 'dismiss', array());
    $check('scraper-finds: dismiss', (kop_scraper_finds_decisions()[$ksl]['decision'] ?? '') === 'dismissed');
    call_user_func($src['act'], $ksl, 'undo', array());
    $check('scraper-finds: back to the list', !isset(kop_scraper_finds_decisions()[$ksl]));
}
