<?php
/**
 * Pull quotes: a sentence or passage lifted out of a long article and set
 * large, the way a magazine does (css/pull-quotes.css, loaded on every page).
 *
 * Three ways to place one, all drawn alike:
 *
 * - The block editor's own Pullquote block (type /pullquote). Nothing here
 *   changes its markup; the stylesheet restyles .wp-block-pullquote.
 * - [kop_pullquote credit="Name, role" align="right"]the words[/kop_pullquote]
 *   anywhere shortcodes run, including a block edited with the pencil.
 *   align: center (default, across the text column), left or right (beside
 *   the text on a wide screen, across the column on a phone).
 * - In page text (js/data/pages/<slug>.json, inc/page-text.php), lines that
 *   start with "> "; a last line "> -- Name" is the credit.
 *
 * The quote is real text, not hidden from screen readers: an editor may pull
 * a passage the article does not otherwise print, and reading a repeated line
 * twice costs less than never hearing a unique one.
 *
 * Tested by scripts/test-pull-quotes.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The pull quote's markup. $html is the quote (inline HTML allowed, run
 * through wp_kses_post when WordPress is loaded); $args: credit, align.
 */
function kop_pull_quote($html, $args = array()) {
    $args = array_merge(array('credit' => '', 'align' => 'center'), (array) $args);

    $html = trim((string) $html);
    // The editor's own paragraph wrapping (wpautop around shortcode content)
    // is not wanted: the quote supplies its own paragraphs.
    $html = preg_replace('#^(?:\s*</p>)+|(?:<p>\s*)+$#i', '', $html);
    $html = trim(preg_replace('#^\s*<p[^>]*>(.*)</p>\s*$#is', '$1', $html));
    if (function_exists('wp_kses_post')) {
        $html = wp_kses_post($html);
    }
    if (trim(strip_tags($html)) === '') {
        return '';
    }

    // Paragraph breaks the editor typed stay paragraphs.
    $paras = preg_split('#\s*</p>\s*<p[^>]*>\s*|\n\s*\n#i', $html);
    $body = '';
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p !== '') $body .= '<p>' . $p . '</p>';
    }

    $align = strtolower(trim((string) $args['align']));
    if (!in_array($align, array('left', 'right'), true)) $align = 'center';

    $credit = trim((string) $args['credit']);
    $credit = preg_replace('/^(?:--|\x{2014}|\x{2013}|-)\s*/u', '', $credit);
    $credit_html = '';
    if ($credit !== '') {
        $credit = function_exists('wp_kses_post') ? wp_kses_post($credit) : htmlspecialchars($credit, ENT_QUOTES, 'UTF-8');
        $credit_html = '<figcaption class="kop-pullquote__credit">' . $credit . '</figcaption>';
    }

    return '<figure class="kop-pullquote kop-pullquote--' . $align . '">'
        . '<blockquote class="kop-pullquote__text">' . $body . '</blockquote>'
        . $credit_html . '</figure>';
}

/** [kop_pullquote credit="" align="center|left|right"]words[/kop_pullquote] */
function kop_pull_quote_shortcode($atts, $content = null) {
    $atts = shortcode_atts(array('credit' => '', 'align' => 'center'), (array) $atts, 'kop_pullquote');
    return kop_pull_quote(do_shortcode(shortcode_unautop((string) $content)), $atts);
}

if (function_exists('add_shortcode')) {
    add_shortcode('kop_pullquote', 'kop_pull_quote_shortcode');
}

if (function_exists('add_action')) {
    add_action('wp_enqueue_scripts', function () {
        $path = get_stylesheet_directory() . '/css/pull-quotes.css';
        if (file_exists($path)) {
            wp_enqueue_style('kop-pull-quotes', get_stylesheet_directory_uri() . '/css/pull-quotes.css', array(), filemtime($path));
        }
    }, 20);
}
