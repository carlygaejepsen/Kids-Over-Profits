<?php
/**
 * Offline check of inc/news-images.php: the page parser on a fixture, and
 * with --live=N the share images and logos it finds on N real articles from
 * tmp/prod.sqlite (fetched with curl from this machine; nothing is saved).
 *
 * Usage (Local's bundled PHP, see CLAUDE.md):
 *   php.exe -n -d extension_dir=<php>/ext -d extension=pdo_sqlite -d extension=curl -d extension=openssl \
 *       scripts/test-news-images.php [--live=15] [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
define('ABSPATH', __DIR__ . '/');
define('WEEK_IN_SECONDS', 604800);
$args = getopt('', array('live::', 'db::'));

class WP_Error {}
function is_wp_error($t) { return $t instanceof WP_Error; }
function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
function wp_remote_get($url, $opts = array()) {
    if (!function_exists('curl_init')) return new WP_Error();
    $ch = curl_init($url);
    $headers = array();
    foreach ($opts['headers'] ?? array() as $k => $v) $headers[] = $k . ': ' . $v;
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => '', CURLOPT_SSL_VERIFYPEER => false));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $body === false ? new WP_Error() : array('code' => $code, 'body' => $body);
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

require dirname(__DIR__) . '/inc/news-images.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$p = kop_news_images_parse('<meta property="og:image" content="//cdn.x.org/a.jpg"><meta name="msapplication-TileImage" content="/tile.png">'
    . '<link rel="icon" sizes="192x192" href="/i192.png"><link rel="icon" sizes="16x16" href="/i16.png">', 'https://x.org/news/1');
$check('protocol-relative share image, large icons only, tile image as a logo',
    $p['photo'] === array('https://cdn.x.org/a.jpg') && in_array('https://x.org/i192.png', $p['logo'], true)
    && in_array('https://x.org/tile.png', $p['logo'], true) && !in_array('https://x.org/i16.png', $p['logo'], true), json_encode($p));
$check('a CAPTCHA page counts as blocked', kop_news_images_blocked(200, '<title>Just a moment...</title>') && kop_news_images_blocked(403, '<html>'));

$live = isset($args['live']) ? max(1, (int) $args['live']) : 0;
if ($live) {
    $db = $args['db'] ?? dirname(__DIR__) . '/tmp/prod.sqlite';
    $pdo = new PDO('sqlite:' . $db);
    $rows = $pdo->query("SELECT id, article_url FROM news_submissions WHERE status IN ('approved','published') AND article_url LIKE 'http%' ORDER BY RANDOM() LIMIT " . $live)->fetchAll(PDO::FETCH_ASSOC);
    $photos = $logos = 0;
    foreach ($rows as $r) {
        list($code, $html) = kop_news_images_get($r['article_url']);
        $via = 'live';
        if (kop_news_images_blocked($code, $html)) {
            $html = kop_news_images_wayback($r['article_url']);
            $via = $html !== '' ? 'wayback' : 'none';
        }
        $f = $html !== '' ? kop_news_images_parse($html, $r['article_url']) : array('photo' => array(), 'logo' => array());
        if ($f['photo']) $photos++;
        if ($f['logo']) $logos++;
        printf("  #%d %-28s %-7s photo:%s logo:%s\n", $r['id'], substr(kop_news_images_host($r['article_url']), 0, 28), $via,
            $f['photo'] ? 'yes' : '-', $f['logo'] ? 'yes' : '-');
    }
    printf("  %d of %d with a share image, %d with a logo on the page\n", $photos, count($rows), $logos);
}

echo "\n" . ($failures ? "$failures FAILED" : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
