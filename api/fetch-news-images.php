<?php
/**
 * Fetch pictures for the saved news articles from the server shell, the same
 * work the hourly WP-Cron job does a batch at a time (inc/news-images.php).
 *
 * CLI only:
 *   php api/fetch-news-images.php                          dry run: how many articles still need one
 *   php api/fetch-news-images.php apply --limit=400 --minutes=25   work through the backlog
 *   php api/fetch-news-images.php apply --ids=446,367      (re)try these articles
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = array('limit' => 50, 'minutes' => 10, 'ids' => '');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(limit|minutes|ids)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[1] === 'ids' ? trim($m[2]) : max(1, (int) $m[2]);
    }
}
$apply = in_array('apply', $argv, true);
set_time_limit(0);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH') || !function_exists('kop_news_images_run')) {
    fwrite(STDERR, "WordPress or inc/news-images.php did not load\n");
    exit(2);
}
require_once ABSPATH . 'wp-admin/includes/image.php';

$ids = $opts['ids'] !== '' ? array_filter(array_map('intval', explode(',', $opts['ids']))) : array();
if (!$apply) {
    $pending = kop_news_images_pending(100000, $ids);
    $images = get_option('kop_news_images', array());
    $have = 0;
    foreach ((array) $images as $row) if (!empty($row['f'])) $have++;
    echo count($pending) . " articles due a try; $have have a photo; " . count((array) get_option('kop_news_logos', array())) . " sites tried for a logo.\n";
    echo "Run with apply to fetch.\n";
    exit(0);
}
list($tried, $photos, $logos) = kop_news_images_run((int) $opts['limit'], 60 * (int) $opts['minutes'], true, $ids, static function ($line) {
    echo $line . "\n";
});
echo "Tried $tried: $photos photos, $logos new logos.\n";
