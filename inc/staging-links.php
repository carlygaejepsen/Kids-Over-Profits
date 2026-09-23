<?php
/**
 * The staging install, and keeping crawlers out of it.
 *
 * https://kidsoverprofits.org/staging/ is a second WordPress on the same
 * domain. It answers 200, it carries no noindex, and it serves a stale copy
 * of most of the site - a copy that no longer receives corrections. The live
 * site's robots.txt covers the whole host, so this is the one lever the theme
 * has over it.
 *
 * It is a request, not a control. It asks polite crawlers not to fetch
 * /staging/; it does not stop anybody reading those pages, and a URL already
 * in an index can stay there. Closing the copy properly means a password on
 * /staging/, or "Discourage search engines" inside the staging install, or
 * taking it down - none of which can be done from here.
 *
 * Links from published content into /staging/ were a separate problem and are
 * not handled here: they were fixed in the database in September 2026.
 * scripts/check-staging-links.py re-checks the rendered pages for any that
 * come back.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ask crawlers to leave the staging copy alone.
 *
 * inc/facility-pages.php disallows /go/ on the same filter; both lines end up
 * in the virtual robots.txt WordPress serves.
 */
function kop_staging_robots($output) {
    if (strpos($output, '/staging/') !== false) {
        return $output;
    }
    return $output . "\nDisallow: /staging/\n";
}
add_filter('robots_txt', 'kop_staging_robots', 20);
