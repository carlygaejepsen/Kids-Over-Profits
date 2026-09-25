<?php
/** Offline smoke tests for utility and legal-document page templates. */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
define('ABSPATH', dirname(__DIR__) . '/');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

$GLOBALS['kop_test_filters'] = array();
$GLOBALS['kop_test_template'] = '';
$GLOBALS['kop_test_page'] = array();
$GLOBALS['kop_test_loop_remaining'] = 0;
function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['kop_test_filters'][$tag][$priority][] = $callback; return true; }
function has_filter($tag, $callback) {
    foreach ($GLOBALS['kop_test_filters'][$tag] ?? array() as $priority => $callbacks) if (in_array($callback, $callbacks, true)) return $priority;
    return false;
}
function remove_filter($tag, $callback, $priority = 10) {
    $GLOBALS['kop_test_filters'][$tag][$priority] = array_values(array_filter($GLOBALS['kop_test_filters'][$tag][$priority] ?? array(), static function ($candidate) use ($callback) { return $candidate !== $callback; }));
    return true;
}
function apply_filters($tag, $value, ...$args) {
    $filters = $GLOBALS['kop_test_filters'][$tag] ?? array();
    ksort($filters);
    foreach ($filters as $callbacks) foreach ($callbacks as $callback) $value = $callback($value, ...$args);
    return $value;
}
function A2A_SHARE_SAVE_add_to_content($content) { return $content . '<p class="test-share-row">Share</p>'; }
add_filter('the_content', 'A2A_SHARE_SAVE_add_to_content', 20);
function is_singular($type = '') { return true; }
function get_page_template_slug() { return $GLOBALS['kop_test_template']; }
function get_queried_object_id() { return 317; }
function get_post_field($field, $post_id = 0) { return $GLOBALS['kop_test_page'][$field] ?? ''; }
function have_posts() { if ($GLOBALS['kop_test_loop_remaining'] > 0) { $GLOBALS['kop_test_loop_remaining']--; return true; } return false; }
function the_post() {}
function get_the_ID() { return (int) ($GLOBALS['kop_test_page']['ID'] ?? 0); }
function the_ID() { echo get_the_ID(); }
function post_class($classes = '') { echo 'class="' . htmlspecialchars($classes, ENT_QUOTES) . '"'; }
function the_title() { echo htmlspecialchars((string) ($GLOBALS['kop_test_page']['post_title'] ?? ''), ENT_QUOTES); }
function has_excerpt() { return trim((string) ($GLOBALS['kop_test_page']['post_excerpt'] ?? '')) !== ''; }
function get_the_excerpt() { return (string) ($GLOBALS['kop_test_page']['post_excerpt'] ?? ''); }
function get_the_content($more_link_text = null, $strip_teaser = false, $post = null) { return (string) ($GLOBALS['kop_test_page']['post_content'] ?? ''); }
function the_content() {
    $content = get_the_content();
    $content = str_replace('[anonymous_doc_portal]', '<form id="anonymous-portal">Upload</form>', $content);
    $content = str_replace('[dlm_no_access]', '<div id="dlm-no-access">Access message</div>', $content);
    echo apply_filters('the_content', $content);
}
function edit_post_link() {}
function get_header() {}
function get_footer() {}
function home_url($path = '/') { return 'https://kidsoverprofits.org' . $path; }
function get_permalink($post_id = 0) { return 'https://kidsoverprofits.org/' . ($GLOBALS['kop_test_page']['post_name'] ?? 'test') . '/'; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_url($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function untrailingslashit($value) { return rtrim((string) $value, '/'); }
function mysql2date($format, $date) { return date($format, strtotime($date)); }
function get_option($name) { return $name === 'date_format' ? 'F j, Y' : ''; }
function get_children($args) { return $GLOBALS['kop_test_attachments'] ?? array(); }
function wp_get_attachment_url($id) { return 'https://kidsoverprofits.org/wp-content/uploads/test-opinion.pdf'; }

// The records database (api/config.php) as kop_seed_pdo() hands it out.
if (!extension_loaded('pdo_sqlite')) exit("pdo_sqlite is required.\n");
$GLOBALS['kop_test_pdo'] = new PDO('sqlite::memory:');
$GLOBALS['kop_test_pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['kop_test_pdo']->exec('CREATE TABLE lawsuits (id INTEGER, case_name TEXT, court TEXT, filing_date TEXT, plaintiffs TEXT, defendants TEXT, document_urls TEXT, publication_status TEXT, updated_at TEXT)');
function kop_seed_pdo() { return $GLOBALS['kop_test_pdo']; }
function kop_test_lawsuits(array $rows) {
    $pdo = $GLOBALS['kop_test_pdo'];
    $pdo->exec('DELETE FROM lawsuits');
    $insert = $pdo->prepare('INSERT INTO lawsuits (id, case_name, court, filing_date, plaintiffs, defendants, document_urls, publication_status, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as $r) $insert->execute(array($r['id'], $r['case_name'], $r['court'], $r['filing_date'], $r['plaintiffs'], $r['defendants'], $r['document_urls'], $r['publication_status'], $r['updated_at']));
}
require ABSPATH . 'inc/utility-pages.php';
require ABSPATH . 'inc/legal-documents.php';

function kop_test_assert($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
}

$utility_share = array('links' => true, 'donate' => true, 'anon-submit' => false, 'contact' => false, 'no-access' => false);
foreach ($utility_share as $slug => $should_share) {
    $config = kop_utility_config($slug);
    kop_test_assert((bool) ($config['share'] ?? false) === $should_share, "$slug share policy");
}
$GLOBALS['kop_test_template'] = 'templates/page-utility.php';
kop_test_assert(kop_utility_no_toc(true) === false, 'utility template suppresses Easy TOC');
$GLOBALS['kop_test_template'] = '';
kop_test_assert(kop_utility_no_toc(true) === true, 'other templates retain Easy TOC behavior');

$case = array(
    'id' => 10,
    'case_name' => 'Richardson v. Elevations RTC',
    'court' => 'Utah Second District Court',
    'filing_date' => '2024-01-14',
    'plaintiffs' => '["Finn Richardson"]',
    'defendants' => '["Elevations RTC"]',
    // Stored the way json_encode writes it, with escaped slashes.
    'document_urls' => json_encode(array('https://kidsoverprofits.org/wp-content/uploads/2024/08/complaint.pdf', 'https://kidsoverprofits.org/richardson-v-elevations-rtc-prelitigation-panel-opinion/')),
    'publication_status' => 'published',
    'updated_at' => '2026-09-20 10:00:00',
);
$draft = array_merge($case, array('id' => 11, 'case_name' => 'Unpublished draft', 'publication_status' => 'pending'));
kop_test_lawsuits(array($draft));
$GLOBALS['kop_test_page'] = array('post_name' => 'richardson-v-elevations-rtc-prelitigation-panel-opinion');
kop_test_assert(kop_legal_document_lawsuit(317) === null, 'an unpublished lawsuit is not shown');
kop_test_lawsuits(array($case, $draft));
$GLOBALS['kop_test_page'] = array('post_name' => 'richardson-v-elevations-rtc-prelitigation-panel-opinion');
$matched = kop_legal_document_lawsuit(317);
kop_test_assert(is_array($matched) && (int) $matched['id'] === 10, 'lawsuit found by linked document URL');
kop_test_assert(kop_legal_document_source_pdf(317) === '', 'no attached PDF yields no source link');
$GLOBALS['kop_test_attachments'] = array((object) array('ID' => 9001));
kop_test_assert(kop_legal_document_source_pdf(317) === 'https://kidsoverprofits.org/wp-content/uploads/test-opinion.pdf', 'attached PDF source URL is returned');
$GLOBALS['kop_test_attachments'] = array();

$fixtures = array(
    'links' => array('Links', '<ul>' . implode('', array_map(static function ($n) { return '<li><a href="https://example.org/' . $n . '">Link ' . $n . '</a></li>'; }, range(1, 5))) . '</ul>'),
    'anon-submit' => array('Anonymous Document Submission', '[anonymous_doc_portal]'),
    'donate' => array('Donate', '<givebutter-widget id="goDx1p"></givebutter-widget>'),
    'contact' => array('Contact', '<a href="mailto:dani@kidsoverprofits.org">dani@kidsoverprofits.org</a>'),
    'no-access' => array('No Access', '[dlm_no_access]'),
);
$preview_dir = ABSPATH . 'tmp/utility-pages';
if (!is_dir($preview_dir) && !mkdir($preview_dir, 0777, true) && !is_dir($preview_dir)) exit("Could not create $preview_dir\n");
foreach ($fixtures as $slug => $fixture) {
    $GLOBALS['kop_test_page'] = array('ID' => 200, 'post_name' => $slug, 'post_title' => $fixture[0], 'post_content' => $fixture[1], 'post_excerpt' => '');
    $GLOBALS['kop_test_template'] = 'templates/page-utility.php';
    $GLOBALS['kop_test_loop_remaining'] = 1;
    ob_start();
    include ABSPATH . 'templates/page-utility.php';
    $html = ob_get_clean();
    kop_test_assert(strpos($html, '<h1 class="entry-title">' . $fixture[0] . '</h1>') !== false, "$slug renders its title");
    kop_test_assert((strpos($html, 'test-share-row') !== false) === $utility_share[$slug], "$slug share output matches policy");
    if ($slug === 'anon-submit') kop_test_assert(strpos($html, 'id="anonymous-portal"') !== false, 'anonymous portal shortcode remains rendered');
    if ($slug === 'no-access') kop_test_assert(strpos($html, 'id="dlm-no-access"') !== false, 'Download Monitor shortcode remains rendered');
    if ($slug === 'donate') kop_test_assert(strpos($html, '<givebutter-widget') !== false, 'Givebutter widget remains rendered');
    if ($slug === 'contact') kop_test_assert(strpos($html, 'mailto:dani@kidsoverprofits.org') !== false, 'contact mailto remains rendered');
    if ($slug === 'links') kop_test_assert(substr_count($html, '<li><a href="https://example.org/') === 5, 'five link cards remain rendered');
    file_put_contents($preview_dir . '/' . $slug . '.html', $html);
}

$GLOBALS['kop_test_page'] = array(
    'ID' => 317,
    'post_name' => 'richardson-v-elevations-rtc-prelitigation-panel-opinion',
    'post_title' => 'Richardson V. Elevations RTC Prelitigation Panel Opinion',
    'post_content' => '<figure><img src="page-1.webp" alt=""></figure><figure><img src="page-2.webp" alt=""></figure><figure><img src="page-3.webp" alt=""></figure><figure><img src="page-4.webp" alt=""></figure>',
    'post_excerpt' => '',
);
$GLOBALS['kop_test_template'] = 'templates/page-legal-document.php';
$GLOBALS['kop_test_loop_remaining'] = 1;
ob_start();
include ABSPATH . 'templates/page-legal-document.php';
$legal_html = ob_get_clean();
foreach (array('Opinion page 1', 'Opinion page 2', 'Opinion page 3', 'Opinion page 4') as $alt) kop_test_assert(strpos($legal_html, 'alt="' . $alt . '"') !== false, "$alt image alt text");
kop_test_assert(strpos($legal_html, '<h1 class="entry-title">Richardson v. Elevations RTC</h1>') !== false, 'lawsuit case name is the legal page H1');
kop_test_assert(strpos($legal_html, 'Utah Second District Court') !== false, 'court metadata is rendered');
kop_test_assert(strpos($legal_html, 'href="https://kidsoverprofits.org/lawsuits/#lawsuit-10"') !== false, 'related lawsuit links to its record');
kop_test_assert(strpos($legal_html, 'Download the source PDF') === false, 'no source link is shown without an attached PDF');
file_put_contents($preview_dir . '/richardson-v-elevations-rtc-prelitigation-panel-opinion.html', $legal_html);

echo "Utility and legal page checks passed; offline markup is in tmp/utility-pages/.\n";
