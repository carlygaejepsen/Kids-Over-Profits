<?php
/**
 * Render the glossary offline.
 *
 * Stubs the few WordPress functions inc/glossary.php touches, renders the
 * page unfiltered and filtered, and checks for the mistakes that would
 * actually reach a reader: a duplicate id, a #link with nothing to land on,
 * markup from the data reaching the page unescaped, a filter that shows the
 * wrong entries.
 *
 * Usage:
 *   php scripts/test-glossary.php
 *   php scripts/test-glossary.php --dump tmp/glossary.html   (a standalone page with the CSS and JS, for a browser)
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', true);

function get_stylesheet_directory() {
    return dirname(__DIR__);
}
function esc_html($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_attr($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_url($url) {
    return htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
}
function selected($a, $b) {
    if ((string) $a === (string) $b) {
        echo ' selected="selected"';
    }
}
function date_i18n($format, $timestamp) {
    return date($format, $timestamp);
}
/* Profiles for a handful of programs, so the linked-tag branch renders. */
function kop_facility_page_url_for_name($name) {
    $known = array(
        'Hyde School'          => 'https://kidsoverprofits.org/hyde/',
        'Spring Ridge Academy' => 'https://kidsoverprofits.org/facility/spring-ridge-academy/',
        'Island View RTC'      => 'https://kidsoverprofits.org/facility/island-view-rtc/',
        'Elevations RTC'       => 'https://kidsoverprofits.org/elevations-rtc/',
    );
    return isset($known[$name]) ? $known[$name] : '';
}
/* Parent company pages for the companies the glossary names. */
function kop_operator_page_url_for_name($name) {
    $known = array(
        'CEDU'               => 'https://kidsoverprofits.org/operator/cedu/',
        'WWASPS'             => 'https://kidsoverprofits.org/operator/world-wide-association-of-specialty-programs-and-schools/',
        'Three Springs Inc.' => 'https://kidsoverprofits.org/operator/three-springs-inc/',
    );
    return isset($known[$name]) ? $known[$name] : '';
}
function apply_filters($hook, $value) {
    return $value;
}
function rest_url($path) {
    return 'https://kidsoverprofits.org/wp-json/' . $path;
}
function add_query_arg($key, $value, $url) {
    return $url . (strpos($url, '?') === false ? '?' : '&') . rawurlencode($key) . '=' . rawurlencode($value);
}

require dirname(__DIR__) . '/inc/glossary.php';

$failures = 0;
function check($ok, $message) {
    global $failures;
    if (!$ok) {
        $failures++;
        echo "FAIL  $message\n";
    }
}

function render($program, $query) {
    ob_start();
    kop_glossary_render_page($program, $query, 'https://kidsoverprofits.org/glossary/');
    return ob_get_clean();
}

function dom_of($html) {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
    libxml_clear_errors();
    return $doc;
}

$data = kop_glossary_data();
check($data !== null, 'glossary.json loads');
if (!$data) {
    exit(1);
}

/* ---- Unfiltered page ---------------------------------------------------- */

$html = render('', '');
$doc = dom_of($html);
$xp = new DOMXPath($doc);

$ids = array();
foreach ($xp->query('//*[@id]') as $el) {
    $id = $el->getAttribute('id');
    check(!isset($ids[$id]), "duplicate id \"$id\"");
    $ids[$id] = true;
}

$entries = $xp->query('//*[contains(@class,"kop-gl-entry")]');
check($entries->length === (int) $data['count'], "renders {$data['count']} entries (got {$entries->length})");
check($xp->query('//*[contains(@class,"kop-gl-entry")][@hidden]')->length === 0, 'no entry hidden unfiltered');

$links = 0;
foreach ($xp->query('//a[starts-with(@href,"#")]') as $a) {
    $target = substr($a->getAttribute('href'), 1);
    $links++;
    check(isset($ids[$target]), "link to #$target (\"" . trim($a->textContent) . "\") has no target");
}

check(stripos($html, '<script') === false, 'no script tag in the output');
check(strpos($html, '**') === false, 'no raw ** left in the output');
check(!preg_match('/(?<![\w"=])\*(?!\*)[^*<>\s][^*<>]*\*/', strip_tags($html)), 'no raw *emphasis* left in the text');

$refs = $xp->query('//a[contains(@class,"kop-gl-ref")]')->length;
check($refs > 100, "cross-references render as links ($refs)");

$profile_tags = $xp->query('//*[contains(@class,"kop-gl-tag--profile")]')->length;
check($profile_tags > 50, "tags for programs with a profile link to it ($profile_tags)");
check($xp->query('//a[contains(@class,"kop-gl-tag-name")][@href="https://kidsoverprofits.org/hyde/"]')->length > 0, 'a Hyde School tag opens /hyde/');
check($xp->query('//*[@id="g-hyde-school"]//*[contains(@class,"kop-gl-profiles")]//a[@href="https://kidsoverprofits.org/hyde/"]')->length === 1, 'the Hyde School heading links its profile');
check($xp->query('//*[@id="g-island-view-and-elevations-rtc"]//*[contains(@class,"kop-gl-profiles")]//a')->length === 2, 'a two-program heading links both profiles (alias used for Island View)');
check($xp->query('//a[contains(@class,"kop-gl-tag-name")][@href="https://kidsoverprofits.org/operator/cedu/"]')->length > 0, 'a CEDU tag opens the CEDU parent company page');
check($xp->query('//a[contains(@class,"kop-gl-tag-name")][contains(@href,"/operator/world-wide")]')->length > 0, 'a "WWASP programs" tag opens the WWASPS page');
check($xp->query('//*[@id="g-three-springs-programs"]//*[contains(@class,"kop-gl-profiles")]//a[@href="https://kidsoverprofits.org/operator/three-springs-inc/"]')->length === 1, 'the Three Springs heading links the company page');
check($xp->query('//a[contains(@class,"kop-gl-tag-filter")][@data-program="vista"]')->length > 0, 'a program without a profile still filters');

/* ---- Filtered ----------------------------------------------------------- */

$program = 'spring-ridge-academy';
$expected = 0;
foreach ($data['programs'] as $p) {
    if ($p['slug'] === $program) {
        $expected = $p['count'];
    }
}
$doc = dom_of(render($program, ''));
$xp = new DOMXPath($doc);
$visible = $xp->query('//*[contains(@class,"kop-gl-entry")][not(@hidden)]');
check($visible->length === $expected, "?program=$program shows $expected entries (got {$visible->length})");
foreach ($visible as $el) {
    check(in_array($program, explode(' ', $el->getAttribute('data-programs')), true), 'visible entry ' . $el->getAttribute('id') . " is tagged $program");
}
foreach ($xp->query('//a[contains(@class,"kop-gl-ref")]') as $a) {
    check(strpos($a->getAttribute('href'), 'https://kidsoverprofits.org/glossary/#') === 0, 'filtered page links refs to the full page');
    break;
}
$status = trim($xp->query('//*[contains(@class,"kop-gl-status")]')->item(0)->textContent);
check($status === "$expected terms tagged Spring Ridge Academy", "status line reads \"$status\"");

$doc = dom_of(render('', 'bathroom'));
$xp = new DOMXPath($doc);
$visible = $xp->query('//*[contains(@class,"kop-gl-entry")][not(@hidden)]');
check($visible->length > 3, "?q=bathroom finds entries ({$visible->length})");

$doc = dom_of(render('', 'zzqqxx'));
$xp = new DOMXPath($doc);
check($xp->query('//*[contains(@class,"kop-gl-none")][not(@hidden)]')->length === 1, 'a search with no hits says so');
check($xp->query('//*[contains(concat(" ",@class," ")," kop-gl-section ")][not(@hidden)]')->length === 0, 'a search with no hits hides every section');

$doc = dom_of(render('not-a-program', ''));
$xp = new DOMXPath($doc);
check($xp->query('//*[contains(@class,"kop-gl-entry")][not(@hidden)]')->length === (int) $data['count'], 'an unknown program shows everything');

$hostile = render('', '<script>alert(1)</script>');
check(stripos($hostile, '<script>alert') === false, 'the search text is escaped');

/* ---- Dump --------------------------------------------------------------- */

$dump = array_search('--dump', $argv, true);
if ($dump !== false && isset($argv[$dump + 1])) {
    $root = dirname(__DIR__);
    $page = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>TTI Glossary</title>'
        . '<style>' . file_get_contents($root . '/css/colors.css') . '</style>'
        . '<style>body{margin:0;background:#F2EEDF;font-family:system-ui,sans-serif}</style>'
        . '<style>' . file_get_contents($root . '/css/glossary.css') . '</style>'
        . '</head><body><div class="kop-gl-page"><header class="kop-gl-header"><h1 class="kop-gl-title">TTI Glossary</h1></header>'
        . render('', '')
        . '</div><script>' . file_get_contents($root . '/js/glossary.js') . '</script></body></html>';
    file_put_contents($argv[$dump + 1], $page);
    echo "Wrote {$argv[$dump + 1]}\n";
}

echo $failures ? "\n$failures failure(s); $links #links checked.\n" : "OK: {$data['count']} entries, $links #links, $refs cross-references.\n";
exit($failures ? 1 : 0);
