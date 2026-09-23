<?php
/**
 * Offline test for the resolver in api/fix-staging-links.php.
 *
 *   php scripts/test-staging-link-fixer.php
 *
 * The tool rewrites links to the old staging copy of the site, and on
 * 2026-09-22 it gained two more places to write: the memorial records table
 * and the Code Snippets table. Both are printed to visitors, and neither is
 * post content, so neither goes through wp_update_post's safety net.
 *
 * What is checked is the decision, not the database: which staging URLs the
 * tool is willing to rewrite and what it turns them into. The rule that
 * matters is that a URL is only rewritten when the live target is known to
 * exist - a stale link is bad, a broken link is worse - so the cases below
 * are the ones where it must refuse, and the shapes the real content uses.
 *
 * The file defines KOP_FSL_TEST before loading the tool, which returns once
 * its functions are defined rather than touching the database.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('KOP_FSL_TEST', true);
define('ABSPATH', dirname(__DIR__) . '/');

// --- WordPress stubs --------------------------------------------------------

/* Published pages this install is pretending to have, by path. */
$GLOBALS['kop_test_pages'] = array(
    'juvenile-justice-timeline'        => 101,
    'experimental-group-psychology'    => 102,
    'document-library-discovery-ranch' => 103,
    'trails-carolina-lawsuits'         => 104,
    'history'                          => 105,
);
/* And one that exists but is not published, which must not be linked to. */
$GLOBALS['kop_test_draft'] = array('secret-draft' => 106);

function home_url($path = '/') { return 'https://kidsoverprofits.org' . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function get_stylesheet_directory() { return ABSPATH; }
function url_to_postid($url) {
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    if (isset($GLOBALS['kop_test_pages'][$path])) {
        return $GLOBALS['kop_test_pages'][$path];
    }
    if (isset($GLOBALS['kop_test_draft'][$path])) {
        return $GLOBALS['kop_test_draft'][$path];
    }
    return 0;
}
function get_post_status($id) {
    return in_array($id, $GLOBALS['kop_test_pages'], true) ? 'publish' : 'draft';
}
/* inc/redirects.php is loaded for real: the tool follows it so a rewritten
 * link lands on the destination instead of bouncing through a 301. It hooks
 * itself up on the way in, which is all this does. */
function add_action() {}
require ABSPATH . 'inc/redirects.php';

require ABSPATH . 'api/fix-staging-links.php';

// --- Harness ----------------------------------------------------------------

$failures = 0;

function check($label, $got, $want) {
    global $failures;
    if ($got === $want) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n     got:  %s\n     want: %s\n", $label, var_export($got, true), var_export($want, true));
}

function ok($label, $got) {
    global $failures;
    if ($got) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n", $label);
}

$S = 'https://kidsoverprofits.org/staging/';
$L = 'https://kidsoverprofits.org/';

echo "-- Which links get rewritten --\n";

check('a published page', kop_fsl_live_target('juvenile-justice-timeline/'),
    $L . 'juvenile-justice-timeline/');
check('an upload, without asking the database',
    kop_fsl_live_target('wp-content/uploads/2025/05/2004-Island-View-Police-Report-Death.pdf'),
    $L . 'wp-content/uploads/2025/05/2004-Island-View-Police-Report-Death.pdf');
check('the old /kids-over-profits/ prefix is dropped',
    kop_fsl_live_target('kids-over-profits/history/'), $L . 'history/');
check('a retired slug follows the theme redirect map, not a 301 at read time',
    kop_fsl_live_target('richardson-complaint/'),
    'https://kidsoverprofits.org/wp-content/uploads/2024/08/Ryan-Faust_Elevations-Lawsuit-2024.pdf');

echo "\n-- Which do not --\n";

check('a page that does not exist', kop_fsl_live_target('no-such-page/'), null);
check('a page that exists but is not published', kop_fsl_live_target('secret-draft/'), null);
check('a theme file that is not on disk',
    kop_fsl_live_target('wp-content/themes/child/js/facility-form-test.js'), null);

echo "\n-- Planning a string --\n";

$content = '<a href="' . $S . 'juvenile-justice-timeline/">one</a> '
    . '<a href="' . $S . 'no-such-page/">two</a> '
    . '<img src="' . $S . 'wp-content/uploads/2025/07/x.jpg">';
$plan = kop_fsl_plan($content);
check('every distinct staging URL is planned', count($plan), 3);
check('the unresolvable one is marked', $plan[$S . 'no-such-page/'], null);

$fixed = kop_fsl_rewrite($content, $plan);
ok('the resolvable links lose /staging/',
    strpos($fixed, $L . 'juvenile-justice-timeline/') !== false
    && strpos($fixed, $L . 'wp-content/uploads/2025/07/x.jpg') !== false);
ok('and the unresolvable one is left exactly as it was',
    strpos($fixed, $S . 'no-such-page/') !== false);
check('a string with no staging URL is untouched',
    kop_fsl_rewrite('<p>plain</p>', kop_fsl_plan('<p>plain</p>')), '<p>plain</p>');

echo "\n-- The real rows found on 2026-09-22 --\n";

/* memorial_victims 5 and 11 (kop_url) and 117 (source_url). */
foreach (array(
    'document-library-discovery-ranch/',
    'trails-carolina-lawsuits/',
    'wp-content/uploads/2025/05/2004-Island-View-Police-Report-Death.pdf',
) as $rest) {
    check('memorial row: ' . $rest, kop_fsl_live_target($rest), $L . $rest);
}

/* The OG Image snippet: a URL inside PHP source, which is just a string. */
$snippet = "add_action('wp_head', function() {\n"
    . "    echo '<meta property=\"og:image\" content=\"" . $S . "wp-content/uploads/2025/08/logo.png\" />';\n"
    . "});";
$snippet_fixed = kop_fsl_rewrite($snippet, kop_fsl_plan($snippet));
ok('the snippet keeps its PHP and loses the staging host',
    strpos($snippet_fixed, "add_action('wp_head'") === 0
    && strpos($snippet_fixed, '/staging/') === false
    && strpos($snippet_fixed, $L . 'wp-content/uploads/2025/08/logo.png') !== false);

echo "\n-- The extra tables --\n";

/* $wpdb is only needed for the prefix here. */
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');
$specs = kop_fsl_extra_tables();
check('two tables beyond post content', count($specs), 2);
$tables = array_map(function ($s) { return $s['table']; }, $specs);
check('the memorial table and the snippets table', $tables,
    array('memorial_victims', 'wpdl_snippets'));
foreach ($specs as $spec) {
    ok($spec['table'] . ' names a key, columns and a label',
        $spec['key'] !== '' && $spec['columns'] && $spec['label'] !== '' && $spec['name'] !== '');
}

echo $failures ? "\n$failures FAILURES\n" : "\nstaging link fixer: PASS\n";
exit($failures ? 1 : 0);
