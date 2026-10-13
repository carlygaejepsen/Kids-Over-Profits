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

/* ---- Made without shortcodes: select words, press "Pull quote" ------------
 *
 * For an admin on the page (inc/inline-edit.php, js/inline-edit.js): selecting
 * words inside the article shows a "Pull quote" button by the selection; it
 * opens a dialog (ref pq:<post>:<block>:<hash>:new) with the words filled in,
 * and Save puts the editor's own Pullquote block before or after the block the
 * words were in, so the WordPress editor still opens it as a Pullquote. Each
 * pull quote block's pencil opens the same fields (pq:...:edit), with a box to
 * take it off the page. Every save offers Undo (pqundo:<token>), which puts the
 * page back exactly as it was if nobody has changed it since.
 */

/** Inline tags a pull quote may keep. */
function kop_pull_quote_allowed_tags() {
    return array('a' => array('href' => true), 'strong' => array(), 'em' => array(), 'b' => array(), 'i' => array(), 'br' => array());
}

/** Tidy typed text or HTML down to the inline tags above. */
function kop_pull_quote_clean($text) {
    $text = trim(str_replace(array("\r\n", "\r"), "\n", (string) $text));
    $text = function_exists('wp_kses') ? wp_kses($text, kop_pull_quote_allowed_tags()) : htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    return trim($text);
}

/** The editor's Pullquote block for these words (line breaks kept as <br>). */
function kop_pull_quote_block($text, $credit = '', $align = 'center') {
    $lines = array();
    foreach (preg_split('/\n+/', kop_pull_quote_clean($text)) as $line) {
        $line = trim($line);
        if ($line !== '') $lines[] = $line;
    }
    if (!$lines) {
        throw new RuntimeException('Type the words for the pull quote.');
    }
    $credit = preg_replace('/^(?:--|\x{2014}|\x{2013}|-)\s*/u', '', kop_pull_quote_clean(preg_replace('/\s+/', ' ', (string) $credit)));
    $align = in_array($align, array('left', 'right'), true) ? $align : '';

    // Core's own save markup: one <p>, an optional <cite>.
    $html = '<figure class="wp-block-pullquote' . ($align !== '' ? ' align' . $align : '') . '"><blockquote><p>'
        . implode('<br>', $lines) . '</p>' . ($credit !== '' ? '<cite>' . $credit . '</cite>' : '') . '</blockquote></figure>';
    return '<!-- wp:pullquote' . ($align !== '' ? ' {"align":"' . $align . '"}' : '') . " -->\n" . $html . "\n<!-- /wp:pullquote -->";
}

/** A Pullquote block read back: text (line breaks for <br>), credit, align. */
function kop_pull_quote_read(array $block) {
    $html = (string) ($block['innerHTML'] ?? '');
    $paras = preg_match_all('#<p[^>]*>(.*?)</p>#is', $html, $m) ? $m[1] : array();
    $text = trim(preg_replace('#<br\s*/?>\s*#i', "\n", implode("\n", $paras)));
    $credit = preg_match('#<cite[^>]*>(.*?)</cite>#is', $html, $c) ? trim($c[1]) : '';
    $align = (string) ($block['attrs']['align'] ?? '');
    return array(
        'text'   => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'credit' => html_entity_decode($credit, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'align'  => in_array($align, array('left', 'right'), true) ? $align : 'center',
    );
}

function kop_pull_quote_fields(array $now, $is_new) {
    $fields = array(
        kop_ie_field('text', 'Words', 'textarea', $now['text'], array('rows' => 5, 'help' => $is_new
            ? 'Copied from what you selected. Trim it to the strongest line; a new line here starts a new line in the quote.'
            : 'A new line here starts a new line in the quote.')),
        kop_ie_field('credit', 'Who said or wrote it', 'text', $now['credit'], array('help' => 'Printed small under the quote. Leave empty for none.')),
        kop_ie_field('align', 'Where it sits', 'select', $now['align'], array('options' => array(
            array('value' => 'center', 'label' => 'Across the text column'),
            array('value' => 'right', 'label' => 'On the right, text wrapping around it'),
            array('value' => 'left', 'label' => 'On the left, text wrapping around it'),
        ), 'help' => 'Beside the text on a computer screen; on a phone every pull quote runs across.')),
    );
    if ($is_new) {
        $fields[] = kop_ie_field('place', 'Put it', 'select', 'after', array('options' => array(
            array('value' => 'after', 'label' => 'After the paragraph you selected from'),
            array('value' => 'before', 'label' => 'Before that paragraph'),
        )));
    } else {
        $fields[] = kop_ie_field('remove', 'Take this pull quote off the page', 'bool', false);
    }
    return $fields;
}

function kop_pull_quote_ie_load(array $p) {
    $post = kop_ie_post_get($p[0] ?? 0);
    $index = (int) ($p[1] ?? -1);
    $hash = (string) ($p[2] ?? '');
    $blocks = kop_ie_post_block($post, $index, $hash);
    if (($p[3] ?? '') === 'new') {
        return array(
            'title'  => 'New pull quote',
            'help'   => 'Sets a line from the article large, like a magazine. Undo is offered after saving.',
            'fields' => kop_pull_quote_fields(array('text' => '', 'credit' => '', 'align' => 'center'), true),
        );
    }
    if (($blocks[$index]['blockName'] ?? '') !== 'core/pullquote') {
        throw new RuntimeException('That part of the page is not a pull quote.');
    }
    return array(
        'title'  => 'Pull quote',
        'fields' => kop_pull_quote_fields(kop_pull_quote_read($blocks[$index]), false),
        'alt'    => array('ref' => 'post:' . $post->ID . ':block:' . $index . ':' . $hash, 'label' => 'Edit its source'),
    );
}

function kop_pull_quote_ie_save(array $p, array $v) {
    $post = kop_ie_post_get($p[0] ?? 0);
    $index = (int) ($p[1] ?? -1);
    $blocks = kop_ie_post_block($post, $index, (string) ($p[2] ?? ''));
    $is_new = ($p[3] ?? '') === 'new';
    if ($is_new && !has_blocks($post->post_content)) {
        throw new RuntimeException('This page is in the old (classic) format. Open it in the WordPress editor and add a Pullquote block there.');
    }
    if (!$is_new && ($blocks[$index]['blockName'] ?? '') !== 'core/pullquote') {
        throw new RuntimeException('That part of the page is not a pull quote.');
    }

    $remove = !$is_new && !empty($v['remove']);
    $piece = $remove ? '' : kop_pull_quote_block($v['text'] ?? '', $v['credit'] ?? '', (string) ($v['align'] ?? 'center'));
    // Every other block goes back byte for byte; only the pull quote changes.
    if ($is_new) {
        $at = ($v['place'] ?? 'after') === 'before' ? $index : $index + 1;
        $head = serialize_blocks(array_slice($blocks, 0, $at));
        $tail = serialize_blocks(array_slice($blocks, $at));
        $content = ($head !== '' ? $head . "\n\n" : '') . $piece . ($tail !== '' ? "\n\n" . $tail : '');
    } else {
        $content = serialize_blocks(array_slice($blocks, 0, $index)) . $piece . serialize_blocks(array_slice($blocks, $index + 1));
    }

    $before = $post->post_content;
    $result = wp_update_post(wp_slash(array('ID' => $post->ID, 'post_content' => $content)), true);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
    kop_ie_purge_post($post->ID);

    $token = wp_generate_password(20, false);
    set_transient('kop_pq_undo_' . $token, array('post' => $post->ID, 'before' => $before, 'after' => md5(get_post_field('post_content', $post->ID, 'raw'))), DAY_IN_SECONDS);
    $message = $is_new ? 'Pull quote added.' : ($remove ? 'Pull quote taken off the page.' : 'Pull quote saved.');
    return array('message' => $message, 'undo' => 'pqundo:' . $token);
}

/** Undo: the page as it was before that save, unless it has changed since. */
function kop_pull_quote_ie_undo(array $p, array $v) {
    $key = 'kop_pq_undo_' . preg_replace('/[^A-Za-z0-9]/', '', (string) ($p[0] ?? ''));
    $saved = get_transient($key);
    if (!is_array($saved)) {
        throw new RuntimeException('That change can no longer be undone here. WordPress keeps the earlier version under Revisions in the editor.');
    }
    $post = kop_ie_post_get($saved['post']);
    if (md5($post->post_content) !== $saved['after']) {
        throw new RuntimeException('The page has changed since. Use Revisions in the WordPress editor to go back.');
    }
    $result = wp_update_post(wp_slash(array('ID' => $post->ID, 'post_content' => $saved['before'])), true);
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_message());
    }
    delete_transient($key);
    kop_ie_purge_post($post->ID);
    return array('message' => 'Undone.');
}

if (function_exists('add_filter')) {
    add_filter('kop_inline_edit_sources', function ($sources) {
        $sources['pq'] = array('load' => 'kop_pull_quote_ie_load', 'save' => 'kop_pull_quote_ie_save');
        $sources['pqundo'] = array('load' => function () { throw new RuntimeException('Nothing to show.'); }, 'save' => 'kop_pull_quote_ie_undo');
        return $sources;
    });
}

if (function_exists('add_action')) {
    add_action('wp_enqueue_scripts', function () {
        $path = get_stylesheet_directory() . '/css/pull-quotes.css';
        if (file_exists($path)) {
            wp_enqueue_style('kop-pull-quotes', get_stylesheet_directory_uri() . '/css/pull-quotes.css', array(), filemtime($path));
        }
    }, 20);
}
