<?php
/**
 * Offline test for inc/resources-list.php, the /resources/ hub module.
 *
 *   php scripts/test-resources-list.php
 *
 * The page is where somebody in trouble lands, so this checks the things that
 * would hurt: an entry with nothing to click and no number to call, a
 * duplicate, an internal link to a page that does not exist (which must be
 * dropped rather than printed as a dead card), and the proposed block leaking
 * to a visitor who cannot edit the page. Nothing is written.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');
define('KOP_REPORTING_SLUG', 'report-abuse'); // inc/reporting-directory.php

// --- WordPress stubs --------------------------------------------------------

// Pages this install is pretending to have. 'families' is deliberately absent,
// so the drop rule gets exercised.
$GLOBALS['kop_test_pages'] = array(
    'researchreports',
    'inspection-reports',
    'location-index',
    'tti-data-submission',
    'report-abuse',
);
$GLOBALS['kop_test_can_edit'] = false;

function apply_filters($tag, $value) { return $value; }
function add_filter() {}
function add_action() {}
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function current_user_can($cap = '') { return (bool) $GLOBALS['kop_test_can_edit']; }
function get_page_by_path($slug) {
    return in_array($slug, $GLOBALS['kop_test_pages'], true) ? (object) array('post_name' => $slug) : null;
}
function get_permalink($page) { return 'https://example.test/' . $page->post_name . '/'; }
function is_singular() { return true; }
function in_the_loop() { return true; }
function is_main_query() { return true; }
function get_the_ID() { return 94; }
function get_post_field($field, $id) { return 'resources'; }
function parse_blocks($content) {
    // Only what the filter needs: top-level block names, in order.
    $blocks = array();
    foreach (preg_split('/(?=<!-- wp:)/', $content) as $chunk) {
        if (trim($chunk) === '') continue;
        preg_match('/<!-- wp:([a-z0-9\/-]+)/', $chunk, $m);
        $blocks[] = array('blockName' => isset($m[1]) ? 'core/' . str_replace('core/', '', $m[1]) : null, 'innerHTML' => $chunk);
    }
    return $blocks;
}
function serialize_blocks($blocks) {
    $out = '';
    foreach ($blocks as $block) { $out .= $block['innerHTML']; }
    return $out;
}

require ABSPATH . 'inc/resources-list.php';

// --- Harness ----------------------------------------------------------------

$failures = 0;

function check($label, $got, $want) {
    global $failures;
    if ($got === $want) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n     got:  %s\n     want: %s\n", $label, json_encode($got), json_encode($want));
}

function contains($label, $html, $needle) {
    global $failures;
    if (strpos($html, $needle) !== false) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n     missing: %s\n", $label, $needle);
}

function lacks($label, $html, $needle) {
    global $failures;
    if (strpos($html, $needle) === false) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n     present but should not be: %s\n", $label, $needle);
}

/** Every entry in a group list, flattened. */
function kop_test_entries($groups) {
    $out = array();
    foreach ($groups as $group) {
        foreach ($group['entries'] as $entry) {
            $out[] = $entry;
        }
    }
    return $out;
}

echo "-- The lists --\n";
$published = kop_resources_groups();
$proposed  = kop_resources_proposed();

foreach (array('published' => $published, 'proposed' => $proposed) as $which => $groups) {
    $bad_group = array();
    foreach ($groups as $group) {
        if (empty($group['heading']) || empty($group['entries'])) {
            $bad_group[] = isset($group['heading']) ? $group['heading'] : '(no heading)';
        }
    }
    check("every $which group has a heading and entries", $bad_group, array());

    $entries = kop_test_entries($groups);
    $nameless = array();
    $unclickable = array();
    foreach ($entries as $entry) {
        if (empty($entry['name'])) {
            $nameless[] = json_encode($entry);
        }
        if (empty($entry['url']) && empty($entry['page']) && empty($entry['contact'])) {
            $unclickable[] = $entry['name'];
        }
    }
    check("every $which entry has a name", $nameless, array());
    check("every $which entry has a link or a number", $unclickable, array());

    $urls = array();
    foreach ($entries as $entry) {
        if (!empty($entry['url'])) {
            $urls[] = rtrim($entry['url'], '/');
        }
    }
    check("no $which URL appears twice", array_keys(array_filter(array_count_values($urls), function ($n) { return $n > 1; })), array());

    $bad_scheme = array();
    foreach ($entries as $entry) {
        if (!empty($entry['url']) && !preg_match('#^https?://#', $entry['url'])) {
            $bad_scheme[] = $entry['name'];
        }
    }
    check("every $which URL is http(s)", $bad_scheme, array());
}

check('the staging URL is gone from the guide', (bool) preg_grep('#/staging/#', array_map(function ($e) {
    return isset($e['url']) ? $e['url'] : '';
}, kop_test_entries($published))), false);

echo "\n-- Resolving an entry --\n";
check('a page on this install resolves', kop_resources_entry_url(array('page' => 'researchreports')), 'https://example.test/researchreports/');
check('a page that is missing resolves to nothing', kop_resources_entry_url(array('page' => 'families')), '');
check('an external URL passes through', kop_resources_entry_url(array('url' => 'https://unsilenced.org')), 'https://unsilenced.org');
check('an entry with neither has no URL', kop_resources_entry_url(array('name' => 'A phone line')), '');

echo "\n-- What a visitor is served --\n";
$GLOBALS['kop_test_can_edit'] = false;
ob_start();
kop_hub_module_resources();
$html = ob_get_clean();

contains('the module renders', $html, 'class="kop-hub-module kop-resources"');
contains('a published group', $html, 'Survivor support');
contains('an external link opens in a new tab', $html, 'target="_blank" rel="noopener"');
contains('the legal action guide links its PDF', $html, 'Survivors-Guide-to-Legal-Action-Against-Troubled-Teen-Industry-Programs.pdf');
$internal = strpos($html, 'href="https://example.test/researchreports/"');
check('an internal link is not a new tab', $internal !== false && strpos(substr($html, $internal, 120), 'target="_blank"') === false, true);
contains('the archived entry is marked', $html, 'class="kop-res-archived"');
lacks('no proposed block for a visitor', $html, 'kop-res-review');
lacks('no crisis line published yet', $html, '988');
lacks('a page this install lacks is not printed', $html, 'The questions to ask a program');

echo "\n-- What an editor is served --\n";
$GLOBALS['kop_test_can_edit'] = true;
ob_start();
kop_hub_module_resources();
$editor_html = ob_get_clean();

contains('the proposed block shows', $editor_html, 'class="kop-res-review"');
contains('it says it is not published', $editor_html, 'Proposed, not published yet');
contains('the crisis line is proposed', $editor_html, 'Call or text 988');
contains('the number is marked as a number', $editor_html, 'class="kop-res-contact"');
lacks('the missing families page is still dropped', $editor_html, 'The questions to ask a program');

echo "\n-- The legacy content filter --\n";
$page_content = "<!-- wp:group -->\n<div class=\"wp-block-group\">links</div>\n<!-- /wp:group -->\n\n<!-- wp:paragraph -->\n<p>Kept.</p>\n<!-- /wp:paragraph -->";
$filtered = kop_resources_strip_legacy_lists($page_content);
lacks('the link groups are dropped', $filtered, 'wp-block-group');
contains('anything else is kept', $filtered, 'Kept.');
check('content with no groups is untouched', kop_resources_strip_legacy_lists('<p>plain</p>'), '<p>plain</p>');

echo $failures ? "\n$failures FAILURES\n" : "\nresources list: PASS\n";
exit($failures ? 1 : 0);
