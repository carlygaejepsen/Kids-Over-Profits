<?php
/**
 * Scheduled jobs run by the server's cron, not by visitors.
 *
 * WordPress normally starts its scheduled jobs (the hourly scans, the people
 * sync, news images, ...) from inside a visitor's page request. On a busy
 * day that puts the heaviest work of the site next to the visitors' page
 * builds, competing for the same PHP workers and the host's 25 database
 * connections.
 *
 * The server's crontab runs wp-cron.php from the command line every five
 * minutes instead (the first field is star-slash-5, spaced here so it does
 * not end this comment):
 *
 *   * /5 * * * * cd ~/public_html && /usr/bin/flock -n -E 0 ~/logs/wp-cron.lock
 *       /opt/cpanel/ea-php82/root/usr/bin/php wp-cron.php >> ~/logs/wp-cron.log 2>&1
 *
 * Each of those runs stamps kop_server_cron_seen. While the stamp is fresh,
 * visitors no longer start the jobs (DISABLE_WP_CRON, which _wp_cron() reads
 * after the theme has loaded). If the crontab line goes away the stamp goes
 * stale and visitors start them again, so the jobs never just stop.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Seconds a server run counts as recent: three missed runs and visitors take over again. */
const KOP_SERVER_CRON_FRESH = 20 * 60;

if (!function_exists('kop_server_cron_is_cli_run')) {
    function kop_server_cron_is_cli_run() {
        return PHP_SAPI === 'cli' && defined('DOING_CRON') && DOING_CRON;
    }
}

if (kop_server_cron_is_cli_run()) {
    // _wp_cron() reads REQUEST_URI, which a command-line run does not have.
    if (!isset($_SERVER['REQUEST_URI'])) {
        $_SERVER['REQUEST_URI'] = '/wp-cron.php';
    }
    // This run does the work itself; it must not also ask for another one.
    if (!defined('DISABLE_WP_CRON')) {
        define('DISABLE_WP_CRON', true);
    }
    update_option('kop_server_cron_seen', time(), true);
} elseif (!defined('DISABLE_WP_CRON') && (int) get_option('kop_server_cron_seen', 0) > time() - KOP_SERVER_CRON_FRESH) {
    define('DISABLE_WP_CRON', true);
}
