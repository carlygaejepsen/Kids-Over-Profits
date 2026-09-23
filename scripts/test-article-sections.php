<?php
/**
 * Offline test for kop_article_sections() in templates/page-article.php: the
 * contents list, the section ids, and the anchor mark on each section.
 *
 *   php scripts/test-article-sections.php
 *
 * The function runs over the rendered content of 29 pages, several of them
 * 40,000 characters of somebody's research, so the thing to protect is that
 * it never damages what it is given. Most of these articles carry no heading
 * at all - advocacy-history, fundamentalist, war-on-drugs and the timelines
 * are bold marker paragraphs from end to end - which is why the marker path
 * exists and why it is tested here as carefully as the heading path.
 *
 * The template is loaded for its functions only: it defines them, then calls
 * get_header(), which the stubs below make a no-op by throwing a known
 * exception once the definitions are in hand.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');

// --- WordPress stubs --------------------------------------------------------

class KopTemplateLoaded extends Exception {}

function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function wp_strip_all_tags($text) { return strip_tags((string) $text); }
/* Close enough to WordPress for slug purposes: lowercase, accents dropped,
 * runs of anything else collapsed to one hyphen. */
function sanitize_title($text) {
    $text = strtolower(trim((string) $text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}
function get_stylesheet_directory() { return ABSPATH; }
function get_stylesheet_directory_uri() { return 'https://example.test'; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function get_header() { throw new KopTemplateLoaded('functions are defined'); }
/* The local PHP build has no mbstring; production does. The template only
 * uses it to measure a marker's length, so plain strlen stands in here. */
if (!function_exists('mb_strlen')) {
    function mb_strlen($text) { return strlen((string) $text); }
}

try {
    require ABSPATH . 'templates/page-article.php';
} catch (KopTemplateLoaded $e) {
    // Expected: everything above get_header() has been defined.
}

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

/** Text with the tags and the added anchor marks taken out, for comparing
 *  what went in against what came back. */
function words($html) {
    $html = preg_replace('~\s*<a class="kop-article-anchor"[^>]*>#</a>~', '', $html);
    return preg_replace('/\s+/', ' ', trim(strip_tags($html)));
}

echo "-- Headings --\n";

$headings = '<h2>The first part</h2><p>Body.</p><h3>A sub part</h3><p>More.</p>'
    . '<h2>The second part</h2><p>Body.</p><h2>The third part</h2><p>End.</p>';
$out = kop_article_sections($headings);

check('every heading is in the contents', count($out['toc']), 4);
check('and each one carries its level',
    array_map(function ($row) { return $row[2]; }, $out['toc']),
    array(2, 3, 2, 2));
check('the ids come from the text',
    array_map(function ($row) { return $row[0]; }, $out['toc']),
    array('the-first-part', 'a-sub-part', 'the-second-part', 'the-third-part'));
ok('the heading keeps its own text', strpos($out['html'], '>The first part') !== false);
check('nothing is lost from the page', words($out['html']), words($headings));

echo "\n-- The anchor mark --\n";

check('one per section', substr_count($out['html'], 'class="kop-article-anchor"'), 4);
ok('it points at its own section',
    strpos($out['html'], 'href="#the-second-part"') !== false);
ok('and says which section it is, for a screen reader',
    strpos($out['html'], 'aria-label="Link to this section: The second part"') !== false);
ok('it sits inside the heading, not after it',
    preg_match('~<h2 id="the-first-part">The first part <a class="kop-article-anchor"[^>]*>#</a></h2>~', $out['html']) === 1);

echo "\n-- Bold marker paragraphs, which most of these articles use --\n";

$markers = '<p><strong>Nineteen sixty-seven</strong></p><p>What happened.</p>'
    . '<p><strong>Nineteen seventy-one</strong></p><p>What happened next.</p>'
    . '<p><strong>Nineteen eighty-four</strong></p><p>And then.</p>';
$out = kop_article_sections($markers);

check('each marker becomes a section', count($out['toc']), 3);
check('the marker paragraph is labelled',
    substr_count($out['html'], 'class="kop-article-marker"'), 3);
check('and gets an anchor of its own',
    substr_count($out['html'], 'class="kop-article-anchor"'), 3);
ok('the bold text is still bold',
    strpos($out['html'], '<strong>Nineteen sixty-seven</strong>') !== false);

echo "\n-- When there is not enough structure to be worth a contents box --\n";

/* Two markers is not a table of contents, and a lone "#" hanging off two
 * paragraphs is just a typo to a reader. */
$thin = '<p><strong>One</strong></p><p>Body.</p><p><strong>Two</strong></p><p>Body.</p>';
$out = kop_article_sections($thin);
check('no contents list', $out['toc'], array());
check('no marker class left behind', substr_count($out['html'], 'kop-article-marker'), 0);
check('and no anchor marks left behind', substr_count($out['html'], 'kop-article-anchor'), 0);
/* The ids stay. The function cannot tell an id it added from one the editor
 * wrote, so stripping them would risk taking somebody's own anchor away; an
 * unused id on a paragraph costs a reader nothing. */
check('the text is exactly as it came in', words($out['html']), words($thin));
check('and nothing is styled as a section',
    preg_replace('~ id="[^"]*"~', '', $out['html']), $thin);

echo "\n-- Things that must not become sections --\n";

$prose = '<p><strong>This is a whole sentence of bold text, which is emphasis rather than a heading.</strong></p>';
$out = kop_article_sections($prose . $prose . $prose);
check('a bold sentence ending in a full stop is not a heading', $out['toc'], array());

$long = '<p><strong>' . str_repeat('a very long line of bold text ', 5) . '</strong></p>';
$out = kop_article_sections($long . $long . $long);
check('nor is a bold paragraph too long to be a title', $out['toc'], array());

echo "\n-- Ids stay unique and usable --\n";

$dupes = '<h2>Overview</h2><p>a</p><h2>Overview</h2><p>b</p><h2>Overview</h2><p>c</p>';
$out = kop_article_sections($dupes);
$ids = array_map(function ($row) { return $row[0]; }, $out['toc']);
check('a repeated title does not repeat its id', $ids,
    array('overview', 'overview-2', 'overview-3'));
check('every anchor href matches an id on the page',
    preg_match_all('~href="#([^"]+)"~', $out['html'], $m) ? $m[1] : array(), $ids);

$existing = '<h2 id="already-here">Kept</h2><p>a</p><h2>Two</h2><p>b</p><h2>Three</h2><p>c</p>';
$out = kop_article_sections($existing);
check('an id the editor set is kept', $out['toc'][0][0], 'already-here');
check('and is not duplicated on the element',
    substr_count($out['html'], 'id="already-here"'), 1);

echo $failures ? "\n$failures FAILURES\n" : "\narticle sections: PASS\n";
exit($failures ? 1 : 0);
