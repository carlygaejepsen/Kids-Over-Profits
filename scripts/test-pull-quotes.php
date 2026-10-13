<?php
/**
 * Pull quotes (inc/pull-quotes.php): the [kop_pullquote] markup, "> " lines
 * in page text (inc/page-text.php), and the "Pull quote" button's dialog
 * (pq: refs through inc/inline-edit.php): add after/before a block, edit,
 * take off, exact Undo, every other block left byte for byte.
 *
 * The add/edit part reads and writes post content with WordPress's own block
 * parser and serializer, taken from a WordPress install (Local's by default).
 *
 *   php -d extension=mbstring scripts/test-pull-quotes.php [--wp=<path to WordPress root>]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
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

/* ---- The "Pull quote" button's dialog, on real block markup ----------------- */

$wp = null;
foreach ($argv as $a) {
    if (strpos($a, '--wp=') === 0) $wp = rtrim(substr($a, 5), '/\\');
}
if ($wp === null) {
    $local = (getenv('USERPROFILE') ?: '') . '/Local Sites/kids-over-profits/app/public';
    $wp = is_dir($local) ? $local : '';
}
$inc = $wp . '/wp-includes';
if ($wp === '' || !is_file($inc . '/class-wp-block-parser.php')) {
    echo "\nSKIP the dialog checks: no WordPress found (pass --wp=<WordPress root>)\n";
} else {
    // WordPress's own parser classes, and its serializer functions lifted out of blocks.php.
    require $inc . '/class-wp-block-parser-block.php';
    require $inc . '/class-wp-block-parser-frame.php';
    require $inc . '/class-wp-block-parser.php';
    $src = file_get_contents($inc . '/blocks.php');
    foreach (array('serialize_block_attributes', 'strip_core_block_namespace', 'get_comment_delimited_block_content', 'serialize_block', 'serialize_blocks', 'parse_blocks') as $fn) {
        if (!preg_match('/^function ' . $fn . '\(.*?^}$/ms', $src, $m)) {
            exit("Cannot find $fn in blocks.php\n");
        }
        eval($m[0]);
    }

    // The WordPress calls inline-edit.php and the dialog make, against one post in memory.
    $GLOBALS['t_post'] = null;
    $GLOBALS['t_transients'] = array();
    function is_admin() { return false; }
    function wp_doing_ajax() { return false; }
    function is_user_logged_in() { return true; }
    function current_user_can() { return true; }
    function get_post($id) { return (int) $id === 7 ? clone $GLOBALS['t_post'] : null; }
    function get_post_field($f, $id) { return $GLOBALS['t_post']->$f; }
    function has_blocks($c) { return strpos((string) $c, '<!-- wp:') !== false; }
    function wp_slash($v) { return $v; }
    function wp_update_post($u) { $GLOBALS['t_post']->post_content = $u['post_content']; return 7; }
    function is_wp_error($v) { return false; }
    function clean_post_cache() {}
    function do_action() {}
    function wp_generate_password() { static $n = 0; return 'tok' . (++$n); }
    function set_transient($k, $v) { $GLOBALS['t_transients'][$k] = $v; }
    function get_transient($k) { return $GLOBALS['t_transients'][$k] ?? false; }
    function delete_transient($k) { unset($GLOBALS['t_transients'][$k]); }
    function wp_kses($t, $allowed) { return preg_replace('/&(?![a-zA-Z]+;|#\d+;)/', '&amp;', strip_tags($t, '<' . implode('><', array_keys($allowed)) . '>')); }
    function wp_json_encode($data, $options = 0) { return json_encode($data, $options); }
    require dirname(__DIR__) . '/inc/inline-edit.php';

    $original = "<!-- wp:paragraph -->\n<p>First paragraph.</p>\n<!-- /wp:paragraph -->\n\n"
        . "<!-- wp:paragraph -->\n<p>Staff signed off checks that were never done.</p>\n<!-- /wp:paragraph -->\n\n"
        . "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>keep\n\n\n\nthese blank lines</code></pre>\n<!-- /wp:code -->\n\n"
        . "<!-- wp:paragraph -->\n<p>Last paragraph.</p>\n<!-- /wp:paragraph -->";
    $GLOBALS['t_post'] = (object) array('ID' => 7, 'post_title' => 'Test', 'post_content' => $original);
    $blocks = parse_blocks($original);
    $second = 2; // [0] para, [1] whitespace, [2] para
    $hash = kop_ie_block_hash($blocks[$second]);

    $load = kop_pull_quote_ie_load(array('7', (string) $second, $hash, 'new'));
    $check('the new dialog asks for words, credit, placement and where', array_column($load['fields'], 'name') === array('text', 'credit', 'align', 'place'));

    $res = kop_pull_quote_ie_save(array('7', (string) $second, $hash, 'new'),
        array('text' => "Staff signed off checks\nthat were never done & nobody knew.", 'credit' => '-- FOX13, 2024', 'align' => 'right', 'place' => 'after'));
    $now = $GLOBALS['t_post']->post_content;
    $piece = kop_pull_quote_block("Staff signed off checks\nthat were never done & nobody knew.", '-- FOX13, 2024', 'right');
    $check('saved: "Pull quote added." with Undo', $res['message'] === 'Pull quote added.' && $res['undo'] === 'pqundo:tok1', json_encode($res));
    $check('...the block goes right after the paragraph, everything else byte for byte',
        str_replace("\n\n" . $piece . "\n\n", '', $now) === $original, $now);
    $after = parse_blocks($now);
    $pq = null;
    foreach ($after as $i => $b) {
        if ($b['blockName'] === 'core/pullquote') { $pq = $i; }
    }
    $check('...WordPress reads it as a Pullquote block aligned right', $pq !== null && ($after[$pq]['attrs']['align'] ?? '') === 'right');
    $check('...in core\'s own markup', $pq !== null && trim($after[$pq]['innerHTML']) === '<figure class="wp-block-pullquote alignright"><blockquote><p>Staff signed off checks<br>that were never done &amp; nobody knew.</p><cite>FOX13, 2024</cite></blockquote></figure>', $pq !== null ? $after[$pq]['innerHTML'] : '');
    $check('...and serializes back unchanged', serialize_blocks($after) === $now);

    $read = kop_pull_quote_read($after[$pq]);
    $check('its pencil reads back the words, credit and placement', $read === array('text' => "Staff signed off checks\nthat were never done & nobody knew.", 'credit' => 'FOX13, 2024', 'align' => 'right'), json_encode($read));
    $pqhash = kop_ie_block_hash($after[$pq]);
    $edit = kop_pull_quote_ie_load(array('7', (string) $pq, $pqhash, 'edit'));
    $check('...offers "take it off" and the source', in_array('remove', array_column($edit['fields'], 'name'), true) && $edit['alt']['ref'] === 'post:7:block:' . $pq . ':' . $pqhash);

    $res2 = kop_pull_quote_ie_save(array('7', (string) $pq, $pqhash, 'edit'), array('text' => $read['text'], 'credit' => '', 'align' => 'center'));
    $now2 = $GLOBALS['t_post']->post_content;
    $after2 = parse_blocks($now2);
    $check('an edit changes only that block (centred, no credit)', $after2[$pq]['blockName'] === 'core/pullquote' && empty($after2[$pq]['attrs']) && strpos($after2[$pq]['innerHTML'], '<cite>') === false
        && str_replace(kop_pull_quote_block($read['text'], '', 'center'), $piece, $now2) === $now);

    try {
        kop_pull_quote_ie_save(array('7', (string) $pq, $pqhash, 'edit'), array('text' => 'x'));
        $check('a stale pencil is refused', false);
    } catch (RuntimeException $e) {
        $check('a stale pencil is refused', strpos($e->getMessage(), 'changed since') !== false);
    }
    try {
        kop_pull_quote_ie_load(array('7', '0', kop_ie_block_hash($after2[0]), 'edit'));
        $check('a paragraph is not opened as a pull quote', false);
    } catch (RuntimeException $e) {
        $check('a paragraph is not opened as a pull quote', true);
    }

    $u = kop_pull_quote_ie_undo(array('tok2'), array());
    $check('Undo puts the page back exactly (before the edit)', $u['message'] === 'Undone.' && $GLOBALS['t_post']->post_content === $now);
    try {
        kop_pull_quote_ie_undo(array('tok2'), array());
        $check('...and only once', false);
    } catch (RuntimeException $e) {
        $check('...and only once', true);
    }

    $pqhash = kop_ie_block_hash(parse_blocks($now)[$pq]);
    kop_pull_quote_ie_save(array('7', (string) $pq, $pqhash, 'edit'), array('text' => 'x', 'remove' => true));
    $removed = $GLOBALS['t_post']->post_content;
    $check('"Take this pull quote off" removes only the block', str_replace($piece, '', $now) === $removed && strpos($removed, 'wp:pullquote') === false);
    $GLOBALS['t_post']->post_content .= "\n<!-- wp:paragraph --><p>Someone else's edit.</p><!-- /wp:paragraph -->";
    try {
        kop_pull_quote_ie_undo(array('tok3'), array());
        $check('Undo refuses when the page changed since', false);
    } catch (RuntimeException $e) {
        $check('Undo refuses when the page changed since', strpos($e->getMessage(), 'Revisions') !== false);
    }

    $GLOBALS['t_post']->post_content = $original;
    $b = parse_blocks($original);
    try {
        kop_pull_quote_ie_save(array('7', '0', kop_ie_block_hash($b[0]), 'new'), array('text' => '  ', 'credit' => '', 'align' => 'center', 'place' => 'before'));
        $check('empty words are refused', false);
    } catch (RuntimeException $e) {
        $check('empty words are refused, the page untouched', $GLOBALS['t_post']->post_content === $original);
    }
    kop_pull_quote_ie_save(array('7', '0', kop_ie_block_hash($b[0]), 'new'), array('text' => 'Up top.', 'credit' => '', 'align' => 'left', 'place' => 'before'));
    $check('"Before that paragraph" puts it first', strpos($GLOBALS['t_post']->post_content, '<!-- wp:pullquote {"align":"left"} -->') === 0);

    /* ---- A company's written history (pqh:, inc/operator-history.php) ---- */

    $t = kop_pull_quote_text("Staff held him down\n\nfor an hour.", '-- A former student', 'right');
    $check('history text form', $t === "> Staff held him down\n>\n> for an hour.\n> -- A former student\n> (right)", $t);
    $check('...reads back', kop_pull_quote_parse_text($t) === array('paras' => array('Staff held him down', 'for an hour.'), 'credit' => 'A former student', 'align' => 'right'));
    $check('a paragraph that only starts with ">" is not a quote', kop_pull_quote_parse_text("> one\nplain line") === null);
    $check('a paragraph whose every line starts with "> " is one', kop_pull_quote_parse_text("> 5 staff\n> held him") !== null);
    $check('the app gets plain words', kop_pull_quote_plain($t) === "\u{201C}Staff held him down for an hour.\u{201D} \u{2014} A former student");
    $check('...and other paragraphs unchanged', kop_pull_quote_plain('Plain.') === 'Plain.');

    // A program made from a company (kind p) keeps the history in kop_phc rows; stand-ins for those two calls.
    $GLOBALS['t_hist'] = array('Sequel opened its first program in 1999.', '', 'Its programs [were sued](https://example.org/case) often.', 'It sold them in 2021.');
    function kop_phc_rows() { return array(44 => array('op' => array('name' => 'Sequel TSI', 'history' => $GLOBALS['t_hist']))); }
    function kop_phc_ie_save($parts, $values) { $GLOBALS['t_hist'] = $values['history']; return array('message' => 'Saved.'); }
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    function esc_url($s) { return (string) $s; }
    function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    function home_url() { return 'https://kidsoverprofits.org/'; }
    require dirname(__DIR__) . '/inc/operator-history.php';

    $paras = kop_pull_quote_history_get('p', 44);
    $check('the history as the page shows it (blank paragraph dropped)', count($paras) === 3);
    $h1 = kop_pull_quote_para_hash($paras[1]);
    $html = kop_operator_history_paragraphs_html($paras, 'p', 44);
    $check('an admin\'s history paragraphs are "Pull quote" targets', strpos($html, '<p data-kop-pq="pqh:p:44:1:' . $h1 . '">Its programs <a href="https://example.org/case" target="_blank" rel="noopener">were sued</a> often.</p>') !== false, $html);

    $load = kop_pull_quote_history_load(array('p', '44', '1', $h1, 'new'));
    $check('the new dialog opens on a history paragraph', array_column($load['fields'], 'name') === array('text', 'credit', 'align', 'place'));
    $res = kop_pull_quote_history_save(array('p', '44', '1', $h1, 'new'), array('text' => 'Its programs were sued often.', 'credit' => 'Court records', 'align' => 'left', 'place' => 'after'));
    $check('saved after that paragraph, the others untouched', $GLOBALS['t_hist'] === array($paras[0], $paras[1], "> Its programs were sued often.\n> -- Court records\n> (left)", $paras[2]), json_encode($GLOBALS['t_hist']));
    $check('...with Undo', $res['message'] === 'Pull quote added.' && strpos($res['undo'], 'pqundo:') === 0);

    $now = kop_pull_quote_history_get('p', 44);
    $html = kop_operator_history_paragraphs_html($now, 'p', 44);
    $h2 = kop_pull_quote_para_hash($now[2]);
    $check('the page draws it as a pull quote with its own pencil',
        strpos($html, '<figure class="kop-pullquote kop-pullquote--left" data-kop-edit="pqh:p:44:2:' . $h2 . ':edit" data-kop-edit-label="this pull quote"><blockquote class="kop-pullquote__text"><p>Its programs were sued often.</p></blockquote><figcaption class="kop-pullquote__credit">Court records</figcaption></figure>') !== false, $html);
    $edit = kop_pull_quote_history_load(array('p', '44', '2', $h2, 'edit'));
    $vals = array_column($edit['fields'], 'value', 'name');
    $check('its pencil reads the words, credit and side back', $vals['text'] === 'Its programs were sued often.' && $vals['credit'] === 'Court records' && $vals['align'] === 'left' && $edit['alt']['ref'] === 'phistory:44');
    try {
        kop_pull_quote_history_load(array('p', '44', '0', kop_pull_quote_para_hash($now[0]), 'edit'));
        $check('a plain paragraph is not opened as a pull quote', false);
    } catch (RuntimeException $e) {
        $check('a plain paragraph is not opened as a pull quote', true);
    }
    $res2 = kop_pull_quote_history_save(array('p', '44', '2', $h2, 'edit'), array('text' => 'x', 'remove' => true));
    $check('"Take this pull quote off" removes only it', $GLOBALS['t_hist'] === $paras);
    try {
        kop_pull_quote_history_save(array('p', '44', '2', $h2, 'edit'), array('text' => 'x'));
        $check('a stale history pencil is refused', false);
    } catch (RuntimeException $e) {
        $check('a stale history pencil is refused', strpos($e->getMessage(), 'changed since') !== false);
    }
    kop_pull_quote_ie_undo(array(substr($res2['undo'], 7)), array());
    $check('Undo brings the pull quote back exactly', $GLOBALS['t_hist'] === $now);
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
