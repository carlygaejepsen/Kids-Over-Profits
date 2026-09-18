<?php
/**
 * Offline test for the relevance tier in inc/research-library.php.
 *
 * The library is built from FileBird folders on the live site, so the grid is
 * rendered here against stub attachments instead: run it before deploying a
 * change to the tier, the sort order or the card markup.
 *
 *   php scripts/test-research-library.php
 *
 * Checks the tier helpers, the "most relevant" order (including that an
 * unrated library keeps the order the page had before tiers existed), and the
 * markup the sort control and the editor dialog depend on. Nothing is written
 * and nothing touches a database. The browser half of the sort lives in
 * scripts/test-research-sort.js.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

// --- WordPress stubs --------------------------------------------------------

define('ABSPATH', dirname(__DIR__) . '/');

$GLOBALS['kop_test_meta'] = array(
    501 => array(
        '_wp_attached_file'           => '2024/01/senate-finance-committee-tti-report-2024.pdf',
        'kop_research_relevance'      => 1,
        'kop_research_relevance_note' => 'The fullest public account of how the money flows.',
    ),
    502 => array(
        '_wp_attached_file' => '2019/05/some-old-study.pdf',
    ),
);

function apply_filters($tag, $value) { return $value; }
function add_filter() {}
function add_action() {}
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function esc_url_raw($url) { return (string) $url; }
function current_user_can() { return true; }   // an editor, so the dialog renders too
function is_page() { return true; }
function get_option($key, $default = false) { return $default; }
function wp_list_pluck($list, $field) {
    return array_map(function ($row) use ($field) { return $row[$field]; }, $list);
}
function get_post_meta($id, $key, $single = false) {
    return isset($GLOBALS['kop_test_meta'][$id][$key]) ? $GLOBALS['kop_test_meta'][$id][$key] : '';
}
function kop_get_folder_attachments($folder_id) {
    if ((int) $folder_id !== 27) {
        return array();
    }
    return array(
        (object) array(
            'ID'             => 501,
            'post_title'     => 'Warehouses of Neglect',
            'post_excerpt'   => 'by U.S. Senate Committee on Finance, 2024',
            'post_content'   => '',
            'post_mime_type' => 'application/pdf',
        ),
        (object) array(
            'ID'             => 502,
            'post_title'     => 'Some Old Study',
            'post_excerpt'   => 'by A Researcher, 2019',
            'post_content'   => 'A description.',
            'post_mime_type' => 'application/pdf',
        ),
    );
}
function kop_resolve_live_attachment($id) { return (int) $id; }
function get_attached_file($id) { return ''; }
function kop_get_attachment_preview_url($id, $size = '') { return "https://example.test/cover-$id.jpg"; }
function wp_get_attachment_url($id) { return "https://example.test/doc-$id.pdf"; }
function wp_get_attachment_image_url($id, $size = '') { return "https://example.test/img-$id.jpg"; }
function wp_check_filetype($file) { return array('ext' => 'pdf', 'type' => 'application/pdf'); }
function size_format($bytes) { return '1 MB'; }
function kop_title_case($text) { return $text; }
function get_permalink($id) { return "https://example.test/page-$id/"; }
function get_page_link($id) { return get_permalink($id); }

require ABSPATH . 'inc/research-library.php';

// --- Harness ----------------------------------------------------------------

$failures = 0;

function check($label, $got, $want) {
    global $failures;
    if ($got === $want) {
        echo "PASS $label\n";
        return;
    }
    $failures++;
    printf("FAIL %s\n     got:  %s\n     want: %s\n", $label, json_encode($got), json_encode($want));
}

function contains($label, $html, $needle) {
    global $failures;
    if (strpos($html, $needle) !== false) {
        echo "PASS $label\n";
        return;
    }
    $failures++;
    printf("FAIL %s\n     missing: %s\n", $label, $needle);
}

function lacks($label, $html, $needle) {
    global $failures;
    if (strpos($html, $needle) === false) {
        echo "PASS $label\n";
        return;
    }
    $failures++;
    printf("FAIL %s\n     present but should not be: %s\n", $label, $needle);
}

echo "-- Tier helpers --\n";
check('tiers are 1 to 3', array_keys(kop_research_relevance_tiers()), array(1, 2, 3));
check('a real tier survives', kop_research_clean_tier(2), 2);
check('0 is unrated', kop_research_clean_tier(0), 0);
check('a tier that does not exist is unrated', kop_research_clean_tier(4), 0);
check('a numeric string is read', kop_research_clean_tier('3'), 3);
check('junk is unrated', kop_research_clean_tier('abc'), 0);
check('a negative tier is unrated', kop_research_clean_tier(-1), 0);

echo "\n-- Most relevant order --\n";
$item = function ($title, $year, $tier) {
    return array('title' => $title, 'year' => $year, 'relevance' => $tier);
};
$items = array(
    $item('Zebra unrated 2024', 2024, 0),
    $item('Background 2020', 2020, 3),
    $item('Start here 1999', 1999, 1),
    $item('Important 2024', 2024, 2),
    $item('Start here 2024', 2024, 1),
    $item('Alpha unrated 2024', 2024, 0),
    $item('Undated unrated', 0, 0),
);
usort($items, 'kop_research_compare_by_relevance');
check('tier first, then newest, then title', array_column($items, 'title'), array(
    'Start here 2024',
    'Start here 1999',
    'Important 2024',
    'Background 2020',
    'Alpha unrated 2024',
    'Zebra unrated 2024',
    'Undated unrated',
));

$plain = array($item('B', 2024, 0), $item('A', 2024, 0), $item('C', 2025, 0), $item('D', 0, 0));
usort($plain, 'kop_research_compare_by_relevance');
check('an unrated library keeps the old order', array_column($plain, 'title'), array('C', 'A', 'B', 'D'));

echo "\n-- Items and markup --\n";
$library = kop_research_library_items();
check('every item carries a tier and a line', array_reduce($library, function ($carry, $row) {
    return $carry && array_key_exists('relevance', $row) && array_key_exists('relevance_note', $row);
}, true), true);
check('the rated document leads', (int) $library[0]['relevance'], 1);
check('its line comes through', $library[0]['relevance_note'], 'The fullest public account of how the money flows.');
check('an unrated document has no line', $library[1]['relevance_note'], '');

ob_start();
kop_hub_module_research();
$html = ob_get_clean();

contains('the sort control renders hidden', $html, '<div class="kop-rl-sort" hidden>');
contains('it offers most relevant', $html, '<option value="relevance">Most relevant</option>');
contains('it offers newest', $html, '<option value="year">Newest</option>');
contains('it offers A to Z', $html, '<option value="title">A to Z</option>');
contains('the tier badge shows its label', $html, 'class="kop-rl-tier kop-rl-tier-1">Start here<');
contains('the line prints', $html, 'class="kop-rl-why">The fullest public account of how the money flows.</p>');
contains('an unrated card holds an empty line', $html, 'class="kop-rl-why" hidden>');
contains('the card carries its tier', $html, 'data-relevance="1"');
contains('the card carries its year', $html, 'data-year="2024"');
contains('the card carries its title', $html, 'data-title="Warehouses of Neglect: How Taxpayers');
contains('the dialog has the tier select', $html, 'class="kop-rl-input-relevance"');
contains('the dialog lists the tiers', $html, 'Tier 1 - Start here');
contains('the dialog has the line field', $html, 'class="kop-rl-input-why"');
lacks('an unrated card shows no badge', $html, 'kop-rl-tier kop-rl-tier-0');

echo $failures ? "\n$failures FAILURES\n" : "\nresearch library: PASS\n";
exit($failures ? 1 : 0);
