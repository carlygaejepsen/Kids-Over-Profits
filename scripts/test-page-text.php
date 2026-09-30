<?php
/**
 * Offline checks for the page text system (inc/page-text.php, the editor in
 * inc/page-text-editor.php) and the /indian-boarding-schools/ and /faq/ templates.
 *
 *   php scripts/test-page-text.php
 *
 * No database: WordPress functions are stubbed and options live in memory.
 * Checks that the format escapes everything and allows only safe links, that
 * the page renders every section with its anchors and cards, and that saving,
 * resetting and clearing edits behave.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

define('ABSPATH', dirname(__DIR__) . '/');
$GLOBALS['kop_test_options'] = array();
$GLOBALS['kop_test_posts'] = 1;

function get_stylesheet_directory() { return dirname(__DIR__); }
function get_stylesheet_directory_uri() { return 'https://kidsoverprofits.org/wp-content/themes/child'; }
function home_url($p = '') { return 'https://kidsoverprofits.org' . $p; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['kop_test_options']) ? $GLOBALS['kop_test_options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function add_action() {}
function wp_enqueue_style() {}
function get_header() { echo '<main>'; }
function get_footer() { echo '</main>'; }
function the_title() { echo 'Indian Boarding Schools and Residential Schools'; }
function has_excerpt() { return false; }
function have_posts() { return $GLOBALS['kop_test_posts']-- > 0; }
function the_post() {}
function get_the_content() { return ''; }
function the_content() {}
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_unslash($v) { return $v; }
function check_admin_referer() { return true; }
function wp_get_current_user() { return new class { public $display_name = 'Tester'; public function exists() { return true; } }; }
function get_page_by_path() { return null; }
function clean_post_cache() {}
function do_action() {}

function current_user_can() { return true; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function admin_url($p = '') { return 'https://kidsoverprofits.org/wp-admin/' . $p; }
function add_query_arg($args, $url) { return $url . '&' . http_build_query($args); }
function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" value="n">'; }
function wp_nonce_url($u) { return $u . '&_wpnonce=n'; }
function wp_create_nonce() { return 'n'; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function wp_date($f, $t) { return date($f, $t); }
function get_edit_post_link() { return ''; }

require dirname(__DIR__) . '/inc/page-text.php';
require dirname(__DIR__) . '/inc/page-text-editor.php';

$failed = 0;
function check($ok, $what) {
    global $failed;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
        $failed++;
    }
}

function render_page($template = 'page-indian-boarding-schools.php') {
    $GLOBALS['kop_test_posts'] = 1;
    ob_start();
    include dirname(__DIR__) . '/templates/' . $template;
    return ob_get_clean();
}

echo "Format\n";
$x = kop_page_text_inline('<script>alert(1)</script> & "q"', 'p');
check(strpos($x, '<script') === false && strpos($x, '&lt;script&gt;') !== false, 'HTML is escaped, never passed through');
check(strpos(kop_page_text_inline('[x](javascript:alert(1))', 'p'), 'href') === false, 'javascript: links are refused');
check(strpos(kop_page_text_inline('[x](data:text/html,hi)', 'p'), 'href') === false, 'data: links are refused');
check(strpos(kop_page_text_inline('[x](https://a.org/" onmouseover="y)', 'p'), 'onmouseover="') === false, 'a quote cannot break out of href');
check(kop_page_text_inline('[a](/glossary/)', 'p') === '<a href="https://kidsoverprofits.org/glossary/">a</a>', 'site paths become full links');
check(kop_page_text_inline('[a](#kop-ibs-support)', 'p') === '<a href="#kop-ibs-support">a</a>', '#anchors are kept');
check(kop_page_text_inline('call 1-866-925-4419 now', 'p') === 'call <a class="p-phone" href="tel:+18669254419">1-866-925-4419</a> now', 'phone numbers become tel: links');
check(kop_page_text_inline('**b** and *i* and [**l**](https://a.org/)', 'p') === '<strong>b</strong> and <em>i</em> and <a href="https://a.org/"><strong>l</strong></a>', 'bold, italic and bold inside a link');
check(kop_page_text_inline('2 * 3 * 4', 'p') === '2 * 3 * 4', 'lone asterisks stay asterisks');
$cards = kop_page_text_body_html("- [Org](https://o.org/): Does things.\n- **Nations**: Their work.", 'cards', 'p');
check(substr_count($cards, 'class="p-org"') === 2 && strpos($cards, '<a class="p-org-name" href="https://o.org/">Org</a><p>Does things.</p>') !== false
    && strpos($cards, '<span class="p-org-name">Nations</span>') !== false, 'cards section turns "name: text" items into cards');
$blocks = kop_page_text_body_html("### Sub\n\nOne\nline two\n\n- a\n  continued\n- b", 'plain', 'p');
check($blocks === "<h3>Sub</h3>\n<p>One line two</p>\n<ul><li>a continued</li><li>b</li></ul>\n", 'subheadings, joined paragraph lines, list continuation');

echo "Page\n";
$defaults = kop_page_text_defaults('indian-boarding-schools');
check(count($defaults) === 9, '9 sections in the page file');
$by_key = array();
foreach ($defaults as $d) {
    $by_key[$d['key']] = $d;
}
$html = render_page();
if (getenv('KOP_DUMP')) {
    file_put_contents(getenv('KOP_DUMP'), $html);
}
foreach (array('note', 'not-ours', 'learn', 'support', 'genocide', 'touch', 'records', 'wrong', 'updated') as $k) {
    check(strpos($html, 'id="kop-ibs-' . $k . '"') !== false, "section $k is on the page");
}
check(strpos($html, 'href="#kop-ibs-support"') !== false && strpos($html, '<section class="kop-ibs-support" id="kop-ibs-support"') !== false, 'the content note links to the support lines');
check(substr_count($html, 'class="kop-ibs-org"') === 7, '7 organization cards');
foreach (array('tel:+18669254419', 'tel:+18552423310', 'tel:988', 'tel:+18447628483') as $tel) {
    check(strpos($html, 'href="' . $tel . '"') !== false, "support line $tel is tap-to-call");
}
check(strpos($html, 'href="https://kidsoverprofits.org/glossary/"') !== false && strpos($html, 'href="https://kidsoverprofits.org/contact/"') !== false, 'site links are absolute');
check(strpos($html, '<em>Haaland v. Brackeen</em>') !== false, 'italic case name');
check(strpos($html, '**') === false && !preg_match('/\]\(/', $html), 'no format syntax left over');
preg_match_all('/href="([^"]+)"/', $html, $m);
check(count($m[1]) === 37, '37 links (' . count($m[1]) . ')');
$bad = array_filter($m[1], function ($u) { return !preg_match('#^(https://|tel:|\#kop-ibs-)#', $u); });
check(!$bad, 'every link is https, tel or an in-page anchor');
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
$errs = array_filter(libxml_get_errors(), function ($e) { return !preg_match('/^Tag (main|header|section) invalid/', trim($e->message)); }); // libxml predates HTML5 tags
check(!$errs, 'the page parses as HTML without errors');
foreach ($errs as $e) {
    echo '       ' . trim($e->message) . ' (line ' . $e->line . ")
";
}

echo "Editor\n";
$_POST = array('sections' => array('wrong' => array('heading' => 'Tell us', 'body' => "New text.\r\n")));
$n = kop_page_text_editor_handle_post('indian-boarding-schools');
$e = kop_page_text_edits();
check($n === '1 section saved. The page shows the new text now.' && $e['indian-boarding-schools']['wrong']['body'] === 'New text.', 'saving one changed section');
check(strpos(render_page(), '<h2 id="kop-ibs-wrong-title">Tell us</h2>') !== false, 'the saved edit is on the page at once');
$n = kop_page_text_editor_handle_post('indian-boarding-schools');
check($n === 'Nothing had changed, so nothing was saved.', 'saving again with no change saves nothing');
$_POST = array('kop_pt_reset' => 'wrong', 'sections' => array('touch' => array('heading' => 'Where they touch', 'body' => 'x')));
$n = kop_page_text_editor_handle_post('indian-boarding-schools');
$e = kop_page_text_edits();
check(!isset($e['indian-boarding-schools']['wrong']) && isset($e['indian-boarding-schools']['touch']), 'reset drops that section and still saves the others');
$_POST = array('sections' => array('touch' => array('heading' => $by_key['touch']['heading'], 'body' => $by_key['touch']['body'])));
kop_page_text_editor_handle_post('indian-boarding-schools');
check(kop_page_text_edits() === array(), 'typing the original text back removes the edit');
update_option('kop_page_text_edits', array('indian-boarding-schools' => array('wrong' => array('heading' => $by_key['wrong']['heading'], 'body' => $by_key['wrong']['body'], 'user' => 'x', 'time' => 1))));
check(kop_page_text_clear_matching('indian-boarding-schools') === 1 && kop_page_text_edits() === array(), 'an edit the repo has caught up with clears itself');

echo "FAQ
";
update_option('kop_page_text_edits', array());
$faq = kop_page_text_defaults('faq');
$questions = array('what-is-the-tti', 'why-sent', 'educational-consultant', 'transporters', 'vocabulary', 'synanon', 'legal', 'licensed',
    'dr-phil', 'some-okay', 'not-abused', 'politics', 'juvenile-justice', 'red-flags', 'help');
$nq = count($questions);
check(array_column($faq, 'key') === array_merge(array('intro'), $questions, array('updated')), "intro, the $nq questions in order, last updated");
$html = render_page('page-faq.php');
if (getenv('KOP_DUMP_FAQ')) {
    file_put_contents(getenv('KOP_DUMP_FAQ'), $html);
}
foreach ($questions as $k) {
    check(strpos($html, '<section id="kop-faq-' . $k . '" aria-labelledby="kop-faq-' . $k . '-title">') !== false
        && strpos($html, '<li><a href="#kop-faq-' . $k . '">') !== false, "question $k is on the page and in the list of questions");
}
check(substr_count($html, '<li><a href="#kop-faq-') === $nq, "the list of questions has exactly the $nq questions");
check(strpos($html, 'class="kop-faq-note" id="kop-faq-intro"') < strpos($html, 'class="kop-faq-toc"'), 'the opening note comes before the list of questions');
check(strpos($html, '**') === false && !preg_match('/\]\(/', $html), 'no format syntax left over');
preg_match_all('/href="([^"]+)"/', $html, $m);
$bad = array_filter($m[1], function ($u) { return !preg_match('#^(https://|\#kop-faq-)#', $u); });
check(!$bad, 'every link is https or an in-page anchor' . ($bad ? ': ' . implode(' ', $bad) : ''));
preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $ld);
$ld = $ld ? json_decode($ld[1], true) : null;
check($ld && $ld['@type'] === 'FAQPage' && count($ld['mainEntity']) === $nq
    && $ld['mainEntity'][0]['name'] === 'What is the troubled teen industry?'
    && strpos($ld['mainEntity'][0]['acceptedAnswer']['text'], '<') === false, "FAQPage structured data: $nq questions, answers as plain text");
libxml_use_internal_errors(true);
libxml_clear_errors();
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
$errs = array_filter(libxml_get_errors(), function ($e) { return !preg_match('/^Tag (main|header|section|nav) invalid/', trim($e->message)); });
check(!$errs, 'the FAQ page parses as HTML without errors');

echo "Editor screen
";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = array();
$_REQUEST = array();
ob_start();
kop_render_page_text_editor();
$picker = ob_get_clean();
check(strpos($picker, 'Choose a page to edit') !== false && strpos($picker, 'kop_page=faq') !== false && strpos($picker, 'kop_page=indian-boarding-schools') !== false, 'with several pages the editor asks which one');
$_REQUEST = array('kop_page' => 'indian-boarding-schools');
update_option('kop_page_text_edits', array('indian-boarding-schools' => array('wrong' => array('heading' => 'Tell us', 'body' => 'Edited.', 'user' => 'Tester', 'time' => 1790000000))));
ob_start();
kop_render_page_text_editor();
$screen = ob_get_clean();
if (getenv('KOP_DUMP_SCREEN')) {
    file_put_contents(getenv('KOP_DUMP_SCREEN'), $screen);
}
check(substr_count($screen, 'class="kop-pt-section"') === 9, 'the editor shows all 9 sections');
check(substr_count($screen, 'class="kop-pt-body"') === 9 && substr_count($screen, 'data-do="link"') === 9, 'each section has a text box and the formatting buttons');
check(strpos($screen, 'Edited by Tester') !== false && substr_count($screen, 'name="kop_pt_reset"') === 1, 'only the edited section offers "Put back the original"');
check(strpos($screen, 'kop-ibs-page kop-pt-preview') !== false && strpos($screen, '<h2 id="kop-ibs-wrong-title">Tell us</h2>') !== false, 'previews use the page styles and show the edit');
check(strpos($screen, 'The page itself has not been created yet.') !== false, 'says when the page does not exist');

echo $failed ? "\n$failed failed\n" : "\nAll checks passed\n";
exit($failed ? 1 : 0);
