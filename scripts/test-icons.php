<?php
/**
 * Offline test for inc/icons.php, the SVG icons that replace emojis.
 *
 *   php scripts/test-icons.php
 *
 * Checks that kop_icon() and the JS kopIcon() print identical markup for every
 * icon (needs node), that every icon the emoji map names exists, and that
 * kop_replace_emojis_in_html() handles text, tags, scripts and <option>s.
 * With tmp/prod.sqlite present it also runs the filter over every published
 * post, block widget and the ACF "icons" values, and fails if a mapped emoji
 * survives in visible text or an <svg> lands inside a tag or script.
 * Nothing is written.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
require $root . '/inc/icons.php';

$fail = 0;
function check($ok, $msg) {
    global $fail;
    if (!$ok) {
        $fail++;
        echo "FAIL: $msg\n";
    }
}

// --- Every mapped icon exists ---------------------------------------------
$paths = kop_icon_paths();
foreach (kop_emoji_icon_map() as $emoji => $pair) {
    check(isset($paths[$pair[0]]), "emoji map names missing icon '{$pair[0]}'");
}
check(kop_icon('no-such-icon') === '', 'unknown icon should print nothing');

// --- PHP and JS print the same markup -------------------------------------
$tmp = tempnam(sys_get_temp_dir(), 'kopicons');
$cases = array();
foreach (array_keys($paths) as $name) {
    $cases[] = array($name, array(), kop_icon($name));
}
$cases[] = array('save', array('label' => 'Save "x" & <y>', 'class' => 'big'), kop_icon('save', array('label' => 'Save "x" & <y>', 'class' => 'big')));
file_put_contents($tmp, kop_icons_js() . "\nvar cases=" . json_encode($cases) . ";\n"
    . "var bad=cases.filter(function(c){return window.kopIcon(c[0],c[1])!==c[2];}).map(function(c){return c[0];});"
    . "process.stdout.write(bad.length?'DIFF '+bad.join(','):'OK');");
$node = trim((string) shell_exec('node -e "global.window={};require(process.argv[1])" ' . escapeshellarg($tmp) . ' 2>&1'));
unlink($tmp);
if ($node === '') {
    echo "skip: node not found, PHP/JS parity not checked\n";
} else {
    check($node === 'OK', "PHP and JS icon markup differ: $node");
}

// --- Filter behaviour on fixtures -----------------------------------------
$out = kop_replace_emojis_in_html('<p title="🔍 Find">✅ Done and 🧑‍✈️️ arrests</p>');
check(strpos($out, 'title=" Find"') === false && strpos($out, 'title="Find"') !== false, 'emoji in attribute should be dropped: ' . $out);
check(substr_count($out, '<svg') === 2, 'two icons expected in text: ' . $out);
check(strpos($out, 'aria-label="Staff arrests or criminal records available"') !== false, 'facility key icon should carry its meaning');
check(!preg_match('/\x{FE0F}/u', $out), 'variation selector left behind');

$out = kop_replace_emojis_in_html('<script>var s = "✅ ok";</script><style>.a:before{content:"✅"}</style><!-- ✅ -->');
check(strpos($out, '<svg') === false, 'scripts, styles and comments must be left alone');

$out = kop_replace_emojis_in_html('<select><option>📋 Copy</option></select>');
check(strpos($out, '<option>Copy</option>') !== false, 'emoji in <option> should be dropped: ' . $out);
check(kop_replace_emojis_in_html('plain text') === 'plain text', 'text without emojis should be unchanged');

// --- Everything published on the site, from the mirror --------------------
$mirror = $root . '/tmp/prod.sqlite';
if (!file_exists($mirror) || !class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "skip: tmp/prod.sqlite or pdo_sqlite not available, live content not checked\n";
} else {
    $db = new PDO('sqlite:' . $mirror);
    $items = array();
    foreach ($db->query("SELECT ID, post_name, post_content FROM wpdl_posts WHERE post_status = 'publish' AND post_type NOT IN ('revision', 'attachment', 'acf-field', 'acf-field-group')") as $r) {
        $items['post ' . $r['ID'] . ' ' . $r['post_name']] = $r['post_content'];
    }
    foreach ($db->query("SELECT option_value FROM wpdl_options WHERE option_name = 'widget_block'") as $r) {
        foreach ((array) @unserialize($r['option_value']) as $k => $w) {
            if (is_array($w) && isset($w['content'])) {
                $items["widget_block $k"] = $w['content'];
            }
        }
    }
    $icons_values = $db->query("SELECT DISTINCT m.meta_value FROM wpdl_postmeta m JOIN wpdl_posts p ON p.ID = m.post_id WHERE m.meta_key = 'icons' AND p.post_status = 'publish'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($icons_values as $i => $v) {
        $items["acf icons $i"] = '<div class="mfb">' . implode(', ', (array) @unserialize($v)) . '</div>';
    }

    $map = kop_emoji_icon_map();
    $keys = array_keys($map);
    usort($keys, function ($a, $b) { return strlen($b) - strlen($a); });
    $any = '/(' . implode('|', array_map(function ($k) { return preg_quote($k, '/'); }, $keys)) . ')/u';
    $replaced = 0;
    foreach ($items as $label => $html) {
        $out = kop_replace_emojis_in_html($html);
        $replaced += substr_count($out, '<svg') - substr_count($html, '<svg');
        // Strip what the filter deliberately leaves: scripts, styles, comments.
        $visible = preg_replace('/<!--.*?-->|<script\b.*?<\/script>|<style\b.*?<\/style>/is', '', $out);
        if (preg_match($any, $visible, $m)) {
            check(false, "$label still shows " . $m[1]);
        }
        if (preg_match('/="[^"]*<svg/', $out)) {
            check(false, "$label has an <svg> inside an attribute");
        }
        if (preg_match('/<script\b(?:(?!<\/script>).)*<svg class="kop-icon/is', $out)) {
            check(false, "$label has an icon inside a script");
        }
    }
    echo count($items) . " published items checked, $replaced emojis became icons\n";
}

echo $fail ? "$fail failure(s)\n" : "all icon checks passed\n";
exit($fail ? 1 : 0);
