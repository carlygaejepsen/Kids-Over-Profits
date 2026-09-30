<?php
/**
 * Offline check of inc/unsilenced-archive.php against a fixture: reading the
 * index and shards, grouping, merging an operator's duplicate ids, escaping,
 * and no build at all. Then, when a real build is in js/data/unsilenced/,
 * that every indexed shard is there and holds as many files as the index says.
 *
 * Usage (Local's bundled PHP; there is no php on the PATH):
 *   php.exe -n scripts/test-unsilenced-archive.php
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
define('ABSPATH', __DIR__ . '/');
$fixture = sys_get_temp_dir() . '/kop-unsilenced-fixture-' . getmypid();
define('KOP_UNSILENCED_DIR', $fixture);
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
require dirname(__DIR__) . '/inc/unsilenced-archive.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// ---- No build ---------------------------------------------------------------
$check('no build: empty index', kop_unsilenced_index()['facilities'] === array());
$check('no build: no archive', kop_unsilenced_archive('f', 7) === null);
$check('no build: nothing rendered', kop_unsilenced_render(null, 'X') === '');

// ---- Fixture ----------------------------------------------------------------
// Facility 7: top-level files and a subfolder. Operators 3 and 4: the same
// company twice, sharing one file.
mkdir($fixture . '/f', 0777, true);
mkdir($fixture . '/o', 0777, true);
$put = function ($path, $data) use ($fixture) { file_put_contents($fixture . '/' . $path, json_encode($data)); };
$put('index.json', array('built' => '2026-09-29', 'md5_checked' => true,
    'facilities' => array('7' => 3, '8' => 0), 'operators' => array('3' => 2, '4' => 2)));
$put('f/7.json', array(
    'folders' => array(array('name' => 'Camp <X>', 'id' => '1AbcdefghijKLMNOP')),
    'files'   => array(
        array('id' => '1Zzzzzzzzzzzzzzzz', 'name' => 'z-report.pdf', 'folder' => 'News'),
        array('id' => '1Aaaaaaaaaaaaaaaa', 'name' => 'Handbook "1998" <b>.pdf', 'folder' => ''),
        array('id' => 'bad id!', 'name' => 'dropped.pdf', 'folder' => ''),
        array('id' => '1Bbbbbbbbbbbbbbbb', 'name' => 'Brochure.pdf', 'folder' => ''),
    ),
));
$put('f/8.json', array('folders' => array(), 'files' => array(array('id' => '1Eeeeeeeeeeeeeeee', 'name' => 'e.pdf', 'folder' => ''))));
$put('o/3.json', array('folders' => array(), 'files' => array(
    array('id' => '1Shared0000000000', 'name' => 'Shared.pdf', 'folder' => ''),
    array('id' => '1OnlyThree0000000', 'name' => 'Three.pdf', 'folder' => ''),
)));
$put('o/4.json', array('folders' => array(), 'files' => array(
    array('id' => '1Shared0000000000', 'name' => 'Shared.pdf', 'folder' => ''),
    array('id' => '1OnlyFour00000000', 'name' => 'Four.pdf', 'folder' => ''),
)));

$index = kop_unsilenced_index(true);
$check('index reads the build', $index['facilities'] === array(7 => 3) && $index['operators'] === array(3 => 2, 4 => 2));
$check('a zero count is left out', !isset($index['facilities'][8]));
$check('cache key names the build', strpos(kop_unsilenced_cache_key(), '2026-09-29') === 0);
$check('an id missing from the index lists nothing', kop_unsilenced_archive('f', 8) === null);

$a = kop_unsilenced_archive('f', 7);
$check('facility archive', is_array($a) && $a['count'] === 3, $a ? $a['count'] . ' files' : 'null');
$check('a malformed Drive id is dropped', $a && strpos(json_encode($a), 'dropped.pdf') === false);
$check('top-level files come first, then subfolders',
    $a && array_column($a['groups'], 'folder') === array('', 'News'));
$check('file links go to Drive', $a && $a['groups'][0]['files'][0]['url'] === 'https://drive.google.com/file/d/1Aaaaaaaaaaaaaaaa/view');
$check('folder links go to Drive', $a && $a['folders'][0]['url'] === 'https://drive.google.com/drive/folders/1AbcdefghijKLMNOP');

$html = kop_unsilenced_render($a, 'Camp & Co');
$check('render says how many and whose', strpos($html, '3 documents about Camp &amp; Co') !== false);
$check('render escapes file and folder names',
    strpos($html, 'Handbook &quot;1998&quot; &lt;b&gt;.pdf') !== false && strpos($html, 'Camp &lt;X&gt;') !== false
    && strpos($html, '<b>') === false);
$check('a subfolder folds away', strpos($html, '<details class="kop-unsilenced-group"><summary>News') !== false);
$check('short top-level lists print open', strpos($html, '</p><ul class="kop-fp-records') !== false);

$o = kop_unsilenced_archive('o', array(3, 4));
$check('an operator merges its duplicate ids, each file once', $o && $o['count'] === 3, $o ? $o['count'] . ' files' : 'null');

// ---- The real build, when present --------------------------------------------
$real = dirname(__DIR__) . '/js/data/unsilenced';
if (is_readable($real . '/index.json')) {
    $raw = json_decode((string) file_get_contents($real . '/index.json'), true);
    $bad = array();
    foreach (array('facilities' => 'f', 'operators' => 'o') as $k => $dir) {
        foreach ((array) ($raw[$k] ?? array()) as $id => $n) {
            $shard = json_decode((string) @file_get_contents("{$real}/{$dir}/{$id}.json"), true);
            if (!is_array($shard) || count($shard['files'] ?? array()) !== (int) $n) $bad[] = "{$dir}/{$id}";
        }
    }
    $check('build: every indexed shard is there with its count', !$bad, $bad ? implode(', ', array_slice($bad, 0, 5)) : count($raw['facilities']) . ' facilities, ' . count($raw['operators']) . ' operators');
    $check('build: checked against md5s', !empty($raw['md5_checked']), 'built from a file list without md5s would list copies KOP holds under other names');
}

// Clean up the fixture.
foreach (array('f/7.json', 'f/8.json', 'o/3.json', 'o/4.json', 'index.json') as $p) @unlink($fixture . '/' . $p);
@rmdir($fixture . '/f');
@rmdir($fixture . '/o');
@rmdir($fixture);

echo $failures ? "\n{$failures} failed.\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
