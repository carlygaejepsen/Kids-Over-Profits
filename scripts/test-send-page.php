<?php
/**
 * Offline test for /send/ (inc/send-page.php, templates/send-page.php).
 *
 *   php scripts/test-send-page.php
 *
 * WordPress is stubbed. Renders the template and checks the form, the bookmarklet,
 * the honeypot, no emoji, and that the query-string prefill is escaped (url="><script>).
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
define('ABSPATH', dirname(__DIR__) . '/');

$fails = 0;
$checks = 0;
function check($label, $cond) {
    global $fails, $checks;
    $checks++;
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$cond) $fails++;
}

$GLOBALS['t_filters'] = array();
function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['t_filters'][$tag][] = $cb; return true; }
function add_action($tag, $cb, $prio = 10, $args = 1) { return add_filter($tag, $cb); }
function apply_filters($tag, $value) { return $value; }
function home_url($path = '/') { return 'https://kidsoverprofits.org' . $path; }
function get_option($k, $d = false) { return $d; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function get_header() {}
function get_footer() {}
function wp_unslash($v) { return $v; }
function kop_followup_fields($id) { return '<div class="kop-followup" data-kop-followup><input type="checkbox" data-kop-followup-check> Email me when this has been reviewed</div>'; }
function kop_icon($name, $opts = array()) { return '<svg class="' . esc_attr($opts['class'] ?? '') . '" aria-hidden="true"></svg>'; }

require ABSPATH . 'inc/send-page.php';

// Prefill cleaning.
$p = kop_send_page_prefill(array('url' => 'javascript:alert(1)', 'title' => 'T', 'text' => 'x'));
check('a non-http url is dropped from the prefill', $p['url'] === '');
$p = kop_send_page_prefill(array('url' => 'https://example.test/a', 'title' => str_repeat('a', 900), 'text' => str_repeat('b', 3000)));
check('prefill is length-capped', mb_strlen($p['title']) === 500 && mb_strlen($p['text']) === 2000);
$p = kop_send_page_prefill(array('url' => array('x'), 'title' => array('y')));
check('array params are ignored', $p['url'] === '' && $p['title'] === '');

function render(array $prefill) {
    $GLOBALS['kop_send_page'] = array('prefill' => $prefill);
    ob_start();
    include ABSPATH . 'templates/send-page.php';
    return ob_get_clean();
}

$html = render(array('url' => '', 'title' => '', 'text' => ''));
check('form present', strpos($html, 'id="kop-send-form"') !== false);
foreach (array('url', 'type', 'title', 'site_name', 'published', 'case_number', 'court', 'bill_number', 'jurisdiction', 'session', 'facility', 'notes', 'website_hp') as $name) {
    check("field $name", strpos($html, 'name="' . $name . '"') !== false);
}
foreach (array('article', 'lawsuit', 'legislation', 'website') as $kind) {
    check("kind option $kind", strpos($html, 'value="' . $kind . '"') !== false);
}
check('honeypot hidden from readers and tab order', preg_match('/aria-hidden="true">\s*<label[^>]*>[^<]*<\/label>\s*<input[^>]*name="website_hp"[^>]*tabindex="-1"/', $html) === 1);
check('follow-up block included', strpos($html, 'data-kop-followup') !== false);
check('review sentence', strpos($html, 'A person reviews everything before it appears') !== false);

$expected = "javascript:(function(){window.open('https://kidsoverprofits.org/send/?url='+encodeURIComponent(location.href)+'&title='+encodeURIComponent(document.title)+'&text='+encodeURIComponent(String(getSelection())).slice(0,2000),'kopsend','width=520,height=760')})()";
check('bookmarklet code is exactly the specified one', kop_send_page_bookmarklet() === $expected);
check('bookmarklet link href', strpos($html, 'href="' . esc_attr($expected) . '"') !== false);
check('bookmarklet code in the copy box', strpos($html, esc_textarea($expected)) !== false);
check('copy button', strpos($html, 'id="kop-send-copy"') !== false);
check('extension and app sections', strpos($html, 'Chrome, Edge, Firefox and Safari') !== false && strpos($html, 'Share button') !== false);

check('no emoji characters', preg_match('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', $html) === 0);
foreach (array('js/send-page.js', 'css/send-page.css', 'templates/send-page.php', 'inc/send-page.php') as $f) {
    check("no emoji in $f", preg_match('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', file_get_contents(ABSPATH . $f)) === 0);
}

// Hostile prefill must not break out of its attribute or element.
$evil = array('url' => 'https://x.test/"><script>alert(1)</script>', 'title' => '"><script>alert(2)</script>', 'text' => '</textarea><script>alert(3)</script>');
$html = render($evil);
check('no raw script tag from prefill', strpos($html, '<script>alert') === false);
check('prefill is escaped in the url attribute', strpos($html, 'https://x.test/&quot;&gt;&lt;script&gt;') !== false);
check('prefill is escaped in the textarea', strpos($html, '&lt;/textarea&gt;&lt;script&gt;alert(3)') !== false);
$p = kop_send_page_prefill(array('url' => $evil['url']));
check('prefill keeps an http url as plain text', strpos($p['url'], 'https://x.test/') === 0);

echo "\n" . ($checks - $fails) . " passed, $fails failed\n";
exit($fails ? 1 : 0);
