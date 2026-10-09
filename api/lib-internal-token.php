<?php
/**
 * A key the site's own jobs send to its own endpoints (header X-KOP-Internal),
 * derived from wp-config.php's secret salts, so nothing new to store or rotate.
 * Used by api/save-news-submission.php (the hourly enrich in
 * api/lib-record-enrich.php updates rows through it). '' when the salts are
 * missing: then no request can claim it.
 */

if (!function_exists('kop_internal_token')) {
    function kop_internal_token($purpose) {
        $secret = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('AUTH_SALT') ? AUTH_SALT : '');
        if (strlen($secret) < 32) return '';
        return hash_hmac('sha256', 'kop-internal:' . $purpose, $secret);
    }
}

if (!function_exists('kop_internal_token_ok')) {
    /** True when this request carries the key for $purpose. */
    function kop_internal_token_ok($purpose) {
        $want = kop_internal_token($purpose);
        $got = (string) ($_SERVER['HTTP_X_KOP_INTERNAL'] ?? '');
        return $want !== '' && $got !== '' && hash_equals($want, $got);
    }
}

if (!function_exists('kop_request_same_site')) {
    /**
     * False when the browser says the request came from another site (Origin or
     * Referer naming another host), so a signed-in admin's cookies can't be used
     * by a page elsewhere. No header at all (curl, server jobs) passes.
     */
    function kop_request_same_site() {
        $from = (string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($from === '' || $from === 'null') return $from === '';
        $host = strtolower((string) parse_url($from, PHP_URL_HOST));
        $own = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (function_exists('home_url')) $own = strtolower((string) parse_url(home_url(), PHP_URL_HOST)) ?: $own;
        $own = preg_replace('/:\d+$/', '', $own);
        return $host !== '' && ($host === $own || preg_replace('/^www\./', '', $host) === preg_replace('/^www\./', '', $own));
    }
}
