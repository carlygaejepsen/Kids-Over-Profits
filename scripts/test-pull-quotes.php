<?php
/**
 * Pull quotes (inc/pull-quotes.php): the [kop_pullquote] markup, and "> "
 * lines in page text (inc/page-text.php) turning into the same thing.
 *
 *   php scripts/test-pull-quotes.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
define('ABSPATH', __DIR__ . '/');
function add_filter() {}
function add_action() {}
function add_shortcode() {}
function shortcode_atts($pairs, $atts) { return array_merge($pairs, array_intersect_key((array) $atts, $pairs)); }
function do_shortcode($s) { return $s; }
function shortcode_unautop($s) { return $s; }
function apply_filters($tag, $value) { return $value; }
require dirname(__DIR__) . '/inc/pull-quotes.php';
require dirname(__DIR__) . '/inc/page-text.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$out = kop_pull_quote('They locked us in a room for days.', array('credit' => 'A former student'));
$check('a quote with a credit', $out === '<figure class="kop-pullquote kop-pullquote--center"><blockquote class="kop-pullquote__text"><p>They locked us in a room for days.</p></blockquote><figcaption class="kop-pullquote__credit">A former student</figcaption></figure>', $out);
$check('empty text prints nothing', kop_pull_quote('  <p> </p> ') === '');
$check('no credit, no caption', strpos(kop_pull_quote('Words.'), 'figcaption') === false);
$check('a typed dash before the credit is dropped (the stylesheet draws one)', strpos(kop_pull_quote('Words.', array('credit' => '-- Jane Doe')), '>Jane Doe<') !== false);
$check('align right', strpos(kop_pull_quote('Words.', array('align' => 'Right')), 'kop-pullquote--right') !== false);
$check('an unknown align is centred', strpos(kop_pull_quote('Words.', array('align' => 'wide')), 'kop-pullquote--center') !== false);

// What wpautop leaves around enclosed shortcode content.
$out = kop_pull_quote("</p>\n<p>First line.</p>\n<p>Second line.</p>\n<p>");
$check('the editor\'s stray paragraph tags are cleaned, two paragraphs kept',
    strpos($out, '<blockquote class="kop-pullquote__text"><p>First line.</p><p>Second line.</p></blockquote>') !== false, $out);
$out = kop_pull_quote_shortcode(array('credit' => 'Staff member', 'align' => 'left'), 'One <em>line</em>.');
$check('the shortcode passes credit and align', strpos($out, 'kop-pullquote--left') !== false && strpos($out, '<em>line</em>') !== false && strpos($out, 'Staff member') !== false, $out);

// Page text.
$html = kop_page_text_body_html("Before.\n\n> They *never* told our parents.\n> It was a secret.\n>\n> Second paragraph.\n> -- A former student\nAfter.\n\n- a list", 'plain', 'x');
$check('"> " lines become one pull quote', substr_count($html, 'class="kop-pullquote ') === 1, $html);
$check('...joined lines, with emphasis', strpos($html, '<p>They <em>never</em> told our parents. It was a secret.</p><p>Second paragraph.</p>') !== false, $html);
$check('...credited by "> -- Name"', strpos($html, '<figcaption class="kop-pullquote__credit">A former student</figcaption>') !== false);
$check('...the paragraph before stays', strpos($html, '<p>Before.</p>') === 0);
$check('...a line without "> " ends the quote and is its own paragraph', strpos($html, "</figure>\n<p>After.</p>") !== false, $html);
$check('...and a list after it is still a list', strpos($html, '<ul><li>a list</li></ul>') !== false);
$html = kop_page_text_body_html("> <script>alert(1)</script>", 'plain', 'x');
$check('page text still never prints HTML', strpos($html, '<script') === false && strpos($html, '&lt;script&gt;') !== false, $html);
$check('a lone ">" with nothing after it prints nothing', trim(kop_page_text_body_html(">", 'plain', 'x')) === '');

$css = file_get_contents(dirname(__DIR__) . '/css/pull-quotes.css');
$check('the stylesheet styles both kinds', strpos($css, 'figure.kop-pullquote') !== false && strpos($css, 'figure.wp-block-pullquote') !== false);
$check('no bright accent as text colour', !preg_match('/(?<![-\w])color:\s*var\(--kop-(orange|teal|coral-pink)[,)]/', $css));

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
