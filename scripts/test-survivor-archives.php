<?php
/**
 * Offline check of inc/survivor-archives.php against a fixture: reading each
 * site's index and shards, keeping the site's groups in order, merging an
 * operator's duplicate ids, refusing links off the site, escaping, and no
 * build at all. Then, when a real build is in js/data/survivor-archives/,
 * that every indexed shard is there, holds as many files as the index says,
 * and links only to its own site.
 *
 * Usage (Local's bundled PHP; there is no php on the PATH):
 *   php.exe -n scripts/test-survivor-archives.php
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
define('ABSPATH', __DIR__ . '/');
$fixture = sys_get_temp_dir() . '/kop-survivor-archives-fixture-' . getmypid();
define('KOP_SURVIVOR_ARCHIVES_DIR', $fixture);
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
require dirname(__DIR__) . '/inc/survivor-archives.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// ---- No build ---------------------------------------------------------------
$check('no build: empty index', kop_survivor_archives_index() === array());
$check('no build: no archives', kop_survivor_archives('f', 7) === array());
$check('no build: nothing rendered', kop_survivor_archives_render(array(), 'X') === '');

// ---- Fixture ----------------------------------------------------------------
// ssi: facility 7 in two groups (one link off the site, one over http), 8 at
// zero. wwasp: facility 7 once, operators 3 and 4 sharing a file.
foreach (array('ssi/f', 'ssi/o', 'wwasp/f', 'wwasp/o') as $d) mkdir($fixture . '/' . $d, 0777, true);
$put = function ($path, $data) use ($fixture) { file_put_contents($fixture . '/' . $path, json_encode($data)); };
$S = 'https://survivingstraightinc.com/';
$W = 'https://wwaspsurvivors.com/wp-content/uploads/';
$put('ssi/index.json', array('built' => '2026-10-01', 'label' => 'Surviving Straight Inc.',
    'facilities' => array('7' => 4, '8' => 0), 'operators' => array()));
$put('ssi/f/7.json', array('files' => array(
    array('url' => $S . 'Tampa/z.pdf', 'name' => 'Zebra "1980" <b>', 'group' => 'St. Petersburg FL'),
    array('url' => $S . 'Tampa/a.pdf', 'name' => '', 'group' => 'St. Petersburg FL'),
    array('url' => 'https://evil.example/x.pdf', 'name' => 'Off the site', 'group' => 'St. Petersburg FL'),
    array('url' => 'http://survivingstraightinc.com/plain.pdf', 'name' => 'Over http', 'group' => 'St. Petersburg FL'),
    array('url' => 'https://www.survivingstraightinc.com/Corp/b.pdf', 'name' => 'Filing', 'group' => 'Corporate docs'),
)));
$put('ssi/f/8.json', array('files' => array(array('url' => $S . 'e.pdf', 'name' => 'e', 'group' => ''))));
$put('wwasp/index.json', array('built' => '2026-10-01', 'label' => 'WWASP Survivors',
    'facilities' => array('7' => 1), 'operators' => array('3' => 2, '4' => 2)));
$put('wwasp/f/7.json', array('files' => array(array('url' => $W . 'm.pdf', 'name' => 'Manual', 'group' => 'A post'))));
$put('wwasp/o/3.json', array('files' => array(
    array('url' => $W . 'shared.pdf', 'name' => 'Shared', 'group' => 'Seminars'),
    array('url' => $W . 'three.pdf', 'name' => 'Three', 'group' => 'Seminars'),
)));
$put('wwasp/o/4.json', array('files' => array(
    array('url' => $W . 'shared.pdf', 'name' => 'Shared', 'group' => 'Seminars'),
    array('url' => $W . 'four.pdf', 'name' => 'Four', 'group' => 'Lawsuit'),
)));

$index = kop_survivor_archives_index(true);
$check('index reads both sites', array_keys($index) === array('ssi', 'wwasp'));
$check('a zero count is left out', !isset($index['ssi']['facilities'][8]));
$check('site url comes from the code, not the build', $index['ssi']['url'] === 'https://survivingstraightinc.com/');
$check('facility counts add up across sites', kop_survivor_archives_facility_counts() === array(7 => 5));
$check('cache key names each build', strpos(kop_survivor_archives_cache_key(), 'ssi:2026-10-01') === 0
    && strpos(kop_survivor_archives_cache_key(), '|wwasp:2026-10-01') !== false);
$check('an id missing from the index lists nothing', kop_survivor_archives('f', 8) === array());

$a = kop_survivor_archives('f', 7);
$check('one block per site', count($a) === 2 && $a[0]['site'] === 'ssi' && $a[1]['site'] === 'wwasp');
$check('links off the site or over http are dropped', $a && $a[0]['count'] === 3
    && strpos(json_encode($a), 'evil.example') === false && strpos(json_encode($a), 'plain.pdf') === false,
    $a ? $a[0]['count'] . ' files' : 'none');
$check('groups keep the site order', $a && array_column($a[0]['groups'], 'group') === array('St. Petersburg FL', 'Corporate docs'));
$check('a missing name falls back to the file name', $a && $a[0]['groups'][0]['files'][1]['name'] === 'a.pdf');

$html = kop_survivor_archives_render($a, 'Straight & Co');
$check('render names each site and says how many',
    strpos($html, 'From Surviving Straight Inc.') !== false && strpos($html, '3 documents about Straight &amp; Co') !== false
    && strpos($html, 'From WWASP Survivors') !== false && strpos($html, '1 document about') !== false);
$check('render escapes names', strpos($html, 'Zebra &quot;1980&quot; &lt;b&gt;') !== false && strpos($html, '<b>') === false);
$check('several groups fold away', strpos($html, '<details class="kop-unsilenced-group"><summary>St. Petersburg FL') !== false);
$check('a single short group prints open', strpos($html, '</p><ul class="kop-fp-records kop-unsilenced-files"><li><a href="' . $W . 'm.pdf"') !== false);

$o = kop_survivor_archives('o', array(3, 4));
$check('an operator merges its duplicate ids, each file once', $o && $o[0]['count'] === 3, $o ? $o[0]['count'] . ' files' : 'none');

// ---- The real build, when present --------------------------------------------
$real = dirname(__DIR__) . '/js/data/survivor-archives';
foreach (kop_survivor_archives_sites() as $site => $host) {
    if (!is_readable("{$real}/{$site}/index.json")) {
        echo "SKIP build: no {$site} build\n";
        continue;
    }
    $raw = json_decode((string) file_get_contents("{$real}/{$site}/index.json"), true);
    $bad = array();
    $off = array();
    foreach (array('facilities' => 'f', 'operators' => 'o') as $k => $dir) {
        foreach ((array) ($raw[$k] ?? array()) as $id => $n) {
            $shard = json_decode((string) @file_get_contents("{$real}/{$site}/{$dir}/{$id}.json"), true);
            if (!is_array($shard) || count($shard['files'] ?? array()) !== (int) $n) $bad[] = "{$dir}/{$id}";
            foreach ((array) ($shard['files'] ?? array()) as $f) {
                if (preg_replace('/^www\./', '', strtolower((string) parse_url($f['url'] ?? '', PHP_URL_HOST))) !== $host
                    || stripos($f['url'], 'https://') !== 0) $off[] = $f['url'] ?? '?';
            }
        }
    }
    $check("build {$site}: every indexed shard is there with its count", !$bad,
        $bad ? implode(', ', array_slice($bad, 0, 5)) : count($raw['facilities']) . ' facilities, ' . count($raw['operators']) . ' operators');
    $check("build {$site}: every link is on {$host}", !$off, $off ? implode(', ', array_slice($off, 0, 3)) : '');
}

// Clean up the fixture.
foreach (array('ssi/f/7.json', 'ssi/f/8.json', 'ssi/index.json', 'wwasp/f/7.json', 'wwasp/o/3.json', 'wwasp/o/4.json', 'wwasp/index.json') as $p) @unlink($fixture . '/' . $p);
foreach (array('ssi/f', 'ssi/o', 'wwasp/f', 'wwasp/o', 'ssi', 'wwasp', '') as $d) @rmdir($fixture . '/' . $d);

echo $failures ? "\n{$failures} failed.\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
