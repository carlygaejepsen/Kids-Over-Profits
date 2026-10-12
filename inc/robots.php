<?php
/**
 * The site's robots.txt rules in one group crawlers actually read.
 *
 * WordPress serves a virtual robots.txt; inc/facility-pages.php (/go/) and
 * inc/staging-links.php (/staging/) each add a Disallow line to it, and Yoast
 * then rebuilds the file around its own "User-agent: *" block. That left our
 * lines above any User-agent line, outside every group, where crawlers ignore
 * them. This runs after Yoast, takes every Disallow line that sits outside a
 * group and puts it, with the paths below, into the "User-agent: *" group.
 *
 * /tti-data-submission: every facility and company page links the data form
 * with ?find=<its name>, so a crawler that follows links builds the form once
 * per record. The form is for people; nothing on it is worth indexing.
 *
 * A request, not a control: a scraper that ignores robots.txt is not stopped.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Paths every crawler is asked to leave alone, besides the ones other files add. */
function kop_robots_disallow_paths() {
    return array('/tti-data-submission');
}

function kop_robots_txt($output) {
    $lines = preg_split('/\r\n|\r|\n/', trim((string) $output));
    $paths = kop_robots_disallow_paths();
    $kept = array();
    $in_group = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*user-agent\s*:/i', $line)) {
            $in_group = true;
        } elseif (!$in_group && preg_match('/^\s*disallow\s*:\s*(\S+)/i', $line, $m)) {
            $paths[] = $m[1]; // outside every group: moved into the one below
            continue;
        }
        $kept[] = $line;
    }

    // The "User-agent: *" group runs from its line to the next blank line.
    $at = null;
    foreach ($kept as $i => $line) {
        if (preg_match('/^\s*user-agent\s*:\s*\*\s*$/i', $line)) {
            $at = $i;
            break;
        }
    }
    if ($at === null) {
        array_unshift($kept, 'User-agent: *', '');
        $at = 0;
    }
    $have = array();
    for ($i = $at + 1; $i < count($kept) && trim($kept[$i]) !== ''; $i++) {
        if (preg_match('/^\s*disallow\s*:\s*(\S*)/i', $kept[$i], $m)) {
            if ($m[1] === '') {
                // An empty Disallow means "allow everything"; beside real rules it says nothing.
                array_splice($kept, $i--, 1);
                continue;
            }
            $have[$m[1]] = true;
        }
    }
    $add = array();
    foreach (array_unique($paths) as $p) {
        if (!isset($have[$p])) {
            $add[] = 'Disallow: ' . $p;
        }
    }
    array_splice($kept, $at + 1, 0, $add);
    return trim(implode("\n", $kept)) . "\n";
}
// After Yoast (99999), which rebuilds the file.
add_filter('robots_txt', 'kop_robots_txt', PHP_INT_MAX);
