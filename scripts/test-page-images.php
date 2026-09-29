<?php
/**
 * Offline check of seeds/page-images.json and inc/page-images.php against
 * tmp/prod.sqlite.
 *
 *   php scripts/test-page-images.php
 *
 * Every entry must name a published page; every in-article entry must find
 * its heading exactly once, and inserting the image block must leave the rest
 * of the page untouched and be idempotent (the block carries wp-image-<id>,
 * which the apply step checks before inserting again). Nothing is written.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');
$mirror = ABSPATH . 'tmp/prod.sqlite';
if (!file_exists($mirror) || !class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    exit("Needs tmp/prod.sqlite and pdo_sqlite (scripts/sync-prod-sqlite.py).\n");
}
$db = new PDO('sqlite:' . $mirror);

// --- WordPress stubs ---------------------------------------------------------
function add_action() {}
function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
function get_stylesheet_directory() { return rtrim(ABSPATH, '/'); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function esc_url($s) { return (string) $s; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_json_encode($v) { return json_encode($v); }
function wp_get_attachment_image_url($id, $size) { return 'https://kidsoverprofits.org/wp-content/uploads/test-' . $id . '.jpg'; }
function get_post_meta($id, $key, $single) { return 'Alt text for ' . $id; }
function wp_get_attachment_caption($id) { return 'Someone, CC BY 4.0, via Wikimedia Commons'; }

require ABSPATH . 'inc/page-images.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
}

$seed = json_decode((string) file_get_contents(kop_page_images_seed_path()), true);
check('seed parses', is_array($seed) && $seed, is_array($seed) ? count($seed) . ' entries' : 'not JSON');
$seen = array();
$fake_id = 900000;
foreach ((array) $seed as $entry) {
    $slug    = (string) ($entry['page'] ?? '');
    $heading = isset($entry['heading']) ? (string) $entry['heading'] : '';
    $label   = $slug . ($heading !== '' ? ' / ' . $heading : ' / featured');
    $key     = $slug . "\x1f" . $heading;
    check("$label listed once", !isset($seen[$key]));
    $seen[$key] = true;
    check("$label has a Commons source URL", strpos((string) ($entry['source_url'] ?? ''), 'https://commons.wikimedia.org/wiki/File:') === 0);

    $stmt = $db->prepare("SELECT post_content FROM wpdl_posts WHERE post_name = ? AND post_type = 'page' AND post_status = 'publish'");
    $stmt->execute(array($slug));
    $content = $stmt->fetchColumn();
    check("$label page is published", $content !== false);
    if ($content === false || $heading === '') {
        continue;
    }
    $block = kop_page_images_block(++$fake_id);
    $new   = kop_page_images_insert_after_heading($content, $heading, $block);
    check("$label heading found exactly once", $new !== null);
    if ($new === null) {
        continue;
    }
    check("$label only adds the block", str_replace("\n\n" . $block, '', $new) === $content);
    check("$label block sits after the heading",
        (bool) preg_match('#<!-- /wp:heading -->\n\n<!-- wp:image \{"id":' . $fake_id . ',#', $new));
    check("$label credit and alt text in the block",
        strpos($block, '<figcaption class="wp-element-caption">Someone, CC BY 4.0') !== false && strpos($block, 'alt="Alt text for') !== false);
}

echo $failures ? "\n$failures failed\n" : "\nall passed\n";
exit($failures ? 1 : 0);
