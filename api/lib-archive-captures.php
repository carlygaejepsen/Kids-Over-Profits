<?php
/**
 * Page text read from archive.today by a person's browser
 * (scripts/archive-today-capture.py), for the AI readers that cannot open
 * archive.today themselves: it puts a CAPTCHA in front of every page a script
 * asks for. The owner solves the CAPTCHA, the script reads the pages in
 * between and the files are copied to ~/kop-import/archive-today/:
 *   text/<md5 of the normalized archive address>.txt   the page's text
 *   index.json   {md5 of the normalized original address: md5 of the archive address}
 * Both addresses find the text, since a news row goes in under its original
 * address once the capture has named it. Never public (whole articles).
 *
 * kop_enrich_document_text() (the review inbox's AI fill, lawsuits) and
 * kop_enrich_news_row() (news) read it before fetching the link.
 */

require_once __DIR__ . '/url-dedupe.php';

if (!function_exists('kop_archive_capture_dir')) {

    function kop_archive_capture_dir() {
        if (defined('KOP_ARCHIVE_CAPTURE_DIR')) {
            return rtrim(KOP_ARCHIVE_CAPTURE_DIR, '/');
        }
        $base = defined('ABSPATH') ? dirname(rtrim(ABSPATH, '/')) : (string) getenv('HOME');
        return $base . '/kop-import/archive-today';
    }

    /** The captured text for this address (archive.today or the page it saved), or ''. */
    function kop_archive_capture_text($url) {
        $norm = kop_normalize_url((string) $url);
        if ($norm === null || $norm === '') {
            return '';
        }
        $dir = kop_archive_capture_dir();
        $hash = md5($norm);
        $file = $dir . '/text/' . $hash . '.txt';
        if (!is_readable($file)) {
            static $index = null;
            if ($index === null) {
                $index = is_readable($dir . '/index.json') ? json_decode((string) file_get_contents($dir . '/index.json'), true) : array();
                $index = is_array($index) ? $index : array();
            }
            if (empty($index[$hash]) || !preg_match('/^[a-f0-9]{32}$/', (string) $index[$hash])) {
                return '';
            }
            $file = $dir . '/text/' . $index[$hash] . '.txt';
            if (!is_readable($file)) {
                return '';
            }
        }
        return trim((string) file_get_contents($file));
    }
}
