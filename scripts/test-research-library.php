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
 * unrated library keeps the order the page had before tiers existed), the
 * facility tags (stored ids, chips, the picker's search and the id validator,
 * against a stub $wpdb) and the markup the sort control, the chips and the
 * editor dialog depend on. Nothing is written and nothing touches a database.
 * The browser half of the sort lives in scripts/test-research-sort.js.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

// --- WordPress stubs --------------------------------------------------------

define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['kop_test_meta'] = array(
    501 => array(
        '_wp_attached_file'           => '2024/01/senate-finance-committee-tti-report-2024.pdf',
        'kop_research_relevance'      => 1,
        'kop_research_relevance_note' => 'The fullest public account of how the money flows.',
    ),
    502 => array(
        '_wp_attached_file' => '2019/05/some-old-study.pdf',
        // Set in the editor: beats the 2019 in the caption.
        'kop_research_year' => 2011,
    ),
    // Edited in place, with a title that reads like its filename.
    503 => array(
        '_wp_attached_file'   => '2024/08/GAO-2022-TTI-report.pdf',
        'kop_research_edited' => '2026-09-29 10:00:00',
    ),
);

// Facility tags: one meta row per facility, and 404 is not a real facility.
$GLOBALS['kop_test_meta'][501]['kop_research_facilities'] = array(10371, 9779, 404);

/**
 * Just enough $wpdb for the facility lookups: the three facilities_v2 columns
 * the chips need, and no other table.
 */
class KOP_Test_Wpdb {
    public $prefix = 'wpdl_';
    public $rows = array(
        10371 => array('id' => 10371, 'name' => 'Provo Canyon School', 'city' => 'Provo', 'state' => 'UT', 'country' => 'United States'),
        9779  => array('id' => 9779,  'name' => '2nd Home, Inc',       'city' => 'Fresno', 'state' => 'CA', 'country' => 'United States'),
        8000  => array('id' => 8000,  'name' => 'Overseas Academy',    'city' => 'Montego Bay', 'state' => '', 'country' => 'Jamaica'),
    );
    public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }
    public function prepare($query, ...$args) {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) (int) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
            $query = preg_replace('/%[ds]/', $replacement, $query, 1);
        }
        return $query;
    }
    private function ids_from($query) {
        if (preg_match('/IN \(([0-9,\s]*)\)/', $query, $m)) {
            return array_filter(array_map('intval', explode(',', $m[1])));
        }
        if (preg_match("/name LIKE '%([^%']*)%'/", $query, $m)) {
            $needle = strtolower($m[1]);
            $hits = array();
            foreach ($this->rows as $id => $row) {
                if ($needle !== '' && strpos(strtolower($row['name']), $needle) !== false) $hits[] = $id;
            }
            return $hits;
        }
        return array();
    }
    public function get_col($query) {
        return array_values(array_filter($this->ids_from($query), function ($id) { return isset($this->rows[$id]); }));
    }
    public function get_results($query, $format = null) {
        $out = array();
        foreach ($this->ids_from($query) as $id) {
            if (isset($this->rows[$id])) $out[] = $this->rows[$id];
        }
        return $out;
    }
}
$GLOBALS['wpdb'] = new KOP_Test_Wpdb();

function kop_facility_page_url($id) {
    return (int) $id === 10371 ? 'https://example.test/facility/provo-canyon-school-ut/' : '';
}
function kop_facility_pages_location_search_url($name) {
    return 'https://example.test/location-index/?search=' . rawurlencode((string) $name);
}
function rest_ensure_response($data) { return $data; }
function register_rest_route() {}
function wp_create_nonce($action) { return 'nonce'; }
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }

/** The one request object the picker's callback needs. */
class KOP_Test_Request {
    private $params;
    public function __construct($params) { $this->params = $params; }
    public function get_param($key) { return isset($this->params[$key]) ? $this->params[$key] : null; }
}

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
    $value = isset($GLOBALS['kop_test_meta'][$id][$key]) ? $GLOBALS['kop_test_meta'][$id][$key] : null;
    if ($single) {
        return is_array($value) ? reset($value) : ($value === null ? '' : $value);
    }
    if ($value === null) {
        return array();
    }
    return is_array($value) ? $value : array($value);
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
        (object) array(
            'ID'                => 503,
            'post_title'        => 'GAO 2022 TTI report',
            'post_excerpt'      => '',
            'post_content'      => '',
            'post_mime_type'    => 'application/pdf',
            // The longer title a sidecar offers; it must not replace a typed one.
            'kop_sidecar_title' => 'Child Welfare HHS Should Facilitate Information Sharing',
        ),
    );
}

// --- What the save callback touches ------------------------------------------

class WP_Error {
    public $code;
    public $message;
    public function __construct($code, $message, $data = array()) {
        $this->code = $code;
        $this->message = $message;
    }
}
function get_post_type($id) { return in_array((int) $id, array(501, 502, 503), true) ? 'attachment' : false; }
function get_post_field($field, $id) {
    $excerpts = array(501 => 'by U.S. Senate Committee on Finance, 2024', 502 => 'by A Researcher, 2019');
    return $field === 'post_excerpt' && isset($excerpts[$id]) ? $excerpts[$id] : '';
}
function wp_update_post($post) { $GLOBALS['kop_test_updated'][$post['ID']] = $post; return $post['ID']; }
function update_post_meta($id, $key, $value) { $GLOBALS['kop_test_meta'][$id][$key] = $value; return true; }
function add_post_meta($id, $key, $value) { $GLOBALS['kop_test_meta'][$id][$key][] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['kop_test_meta'][$id][$key]); return true; }
function current_time($type) { return '2026-09-29 12:00:00'; }
function delete_transient($key) { return true; }
function update_option($key, $value, $autoload = null) { return true; }
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

echo "\n-- Facility tags --\n";
check('stored ids come back', kop_research_facility_ids('att:501'), array(10371, 9779, 404));
check('an untagged document has none', kop_research_facility_ids('att:502'), array());
check('a key that is neither shape is empty', kop_research_facility_ids('nope'), array());
check('only real facilities pass the validator', kop_research_valid_facility_ids(array(10371, 404, 9779)), array(10371, 9779));
check('the validator drops junk', kop_research_valid_facility_ids(array('x', 0, null)), array());

$chips = kop_research_facility_chips(kop_research_facility_ids('att:501'));
check('a chip per real facility, by name', array_column($chips, 'name'), array('2nd Home, Inc', 'Provo Canyon School'));
check('a facility with a page links to it', $chips[1]['url'], 'https://example.test/facility/provo-canyon-school-ut/');
check('one without falls back to the location index', $chips[0]['url'], 'https://example.test/location-index/?search=2nd%20Home%2C%20Inc');
check('the place rides along', $chips[1]['place'], 'Provo, UT');
$abroad = kop_research_facility_chips(array(8000));
check('no state, so the country', $abroad[0]['place'], 'Montego Bay, Jamaica');

$found = kop_research_search_facilities(new KOP_Test_Request(array('q' => 'Provo')));
check('the picker finds a facility', array_column($found['results'], 'id'), array(10371));
check('a one-letter query is not a search', kop_research_search_facilities(new KOP_Test_Request(array('q' => 'P'))), array('results' => array()));

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
contains('the card lists the programs it names', $html, 'class="kop-rl-facilities-label">Programs named:</span>');
contains('each program is a chip linking to its page', $html, 'class="kop-rl-chip" href="https://example.test/facility/provo-canyon-school-ut/"');
contains('the chip carries its id for the editor', $html, 'data-id="10371"');
contains('an untagged card hides the line', $html, 'class="kop-rl-facilities" hidden>');
lacks('a facility that no longer exists is not linked', $html, 'data-id="404"');
contains('the dialog has the tag box', $html, 'class="kop-rl-tags"');
contains('the dialog has the facility search', $html, 'class="kop-rl-tag-query"');

echo "\n-- Title and year --\n";
check('a real year survives', kop_research_clean_year('2022'), 2022);
check('next year is allowed', kop_research_clean_year((int) gmdate('Y') + 1), (int) gmdate('Y') + 1);
check('a year too far out is not a year', kop_research_clean_year((int) gmdate('Y') + 2), 0);
check('a year before 1800 is not a year', kop_research_clean_year(1066), 0);
check('junk is not a year', kop_research_clean_year('soon'), 0);

$by_key = array();
foreach ($library as $row) {
    $by_key[$row['key']] = $row;
}
check('the editor year beats the caption', $by_key['att:502']['year'], 2011);
check('the seed year stands where none is set', $by_key['att:501']['year'], 2024);
check('with neither, the filename year', $by_key['att:503']['year'], 2022);
check('an edited title shows as typed, even one that reads like the filename', $by_key['att:503']['title'], 'GAO 2022 TTI report');
contains('the card carries its kind for the editor', $html, 'data-kind="Academic"');
contains('the kind line shows the editor year', $html, '<span class="kop-rl-kind">Academic / 2011</span>');
contains('the dialog has the year field', $html, 'class="kop-rl-input-year"');

$save = function ($key, $year) {
    return kop_research_save_entry(new KOP_Test_Request(array(
        'key' => $key, 'title' => 'Some Old Study', 'year' => $year, 'description' => '',
        'cover_id' => 0, 'relevance' => 0, 'relevance_note' => '', 'facilities' => array(),
    )));
};
$saved = $save('att:502', 1997);
check('a saved year comes back', array($saved['year'], $saved['year_set']), array(1997, 1997));
check('and is stored on the document', get_post_meta(502, KOP_RESEARCH_YEAR_META, true), 1997);
$saved = $save('att:502', 0);
check('clearing it deletes the stored year', get_post_meta(502, KOP_RESEARCH_YEAR_META, true), '');
check('and the card goes back to the guessed year', array($saved['year'], $saved['year_set']), array(2019, 0));
$refused = $save('att:502', 1066);
check('a year out of range is refused', $refused instanceof WP_Error ? $refused->code : 'saved', 'kop_research_year');
check('and nothing is stored', get_post_meta(502, KOP_RESEARCH_YEAR_META, true), '');

echo $failures ? "\n$failures FAILURES\n" : "\nresearch library: PASS\n";
exit($failures ? 1 : 0);
