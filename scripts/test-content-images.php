<?php
/**
 * kop_content_image_mark_fill() (inc/content-images.php): wide content
 * pictures get .kop-img-fill, small and floated ones are left alone.
 *
 *   php scripts/test-content-images.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
define('ABSPATH', __DIR__ . '/');
function add_filter() {}
function add_action() {}
function wp_get_attachment_metadata($id) { return $id === 7 ? array('width' => 1200) : array('width' => 300); }
require dirname(__DIR__) . '/inc/content-images.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$has = function ($tag) { return (bool) preg_match('/class="[^"]*\bkop-img-fill\b/', $tag); };

$wide = '<img decoding="async" width="2560" height="1439" src="a.jpg" alt="" class="wp-image-484">';
$out = kop_content_image_mark_fill($wide, 484);
$check('a 2560px picture is marked', $has($out), $out);
$check('...keeping its own class', strpos($out, 'wp-image-484 kop-img-fill') !== false);
$check('marking twice adds the class once', substr_count(kop_content_image_mark_fill($out, 484), 'kop-img-fill') === 1);

$check('a 300px logo is not marked', !$has(kop_content_image_mark_fill('<img width="300" height="100" src="l.png" class="wp-image-9">', 9)));
$check('a floated wide picture is not marked', !$has(kop_content_image_mark_fill('<img width="1200" src="a.jpg" class="alignright size-large">', 0)));
$check('no width attribute: the attachment\'s width decides (1200)', $has(kop_content_image_mark_fill('<img src="a.jpg" class="wp-image-7">', 7)));
$check('no width attribute: the attachment\'s width decides (300)', !$has(kop_content_image_mark_fill('<img src="a.jpg" class="wp-image-8">', 8)));
$out = kop_content_image_mark_fill('<img width="800" src="a.jpg">', 0);
$check('a picture with no class attribute gets one', strpos($out, '<img class="kop-img-fill" width="800"') === 0, $out);
$check('single-quoted class is kept', $has(str_replace("'", '"', kop_content_image_mark_fill("<img width='900' class='x' src='a.jpg'>", 0))));

$css = file_get_contents(dirname(__DIR__) . '/css/content-images.css');
$check('the stylesheet fills the column with marked pictures', strpos($css, 'img.kop-img-fill') !== false && strpos($css, 'width: 100%') !== false);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
