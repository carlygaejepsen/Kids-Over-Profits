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

// ---- thestraights.net is http only; the other sites need https ---------------------
$check('scheme: http for thestraights.net alone',
    kop_survivor_archives_scheme('straights') === 'http' && kop_survivor_archives_scheme('ssi') === 'https'
    && kop_survivor_archives_scheme('nhym') === 'https');
foreach (array('straights/f', 'nhym/f') as $d) mkdir($fixture . '/' . $d, 0777, true);
$put('straights/index.json', array('built' => '2026-10-01', 'label' => 'thestraights.net', 'facilities' => array('9' => 3), 'operators' => array()));
$put('straights/f/9.json', array('files' => array(
    array('url' => 'http://thestraights.net/docs/a.pdf', 'name' => 'Over http', 'group' => 'G'),
    array('url' => 'https://thestraights.net/docs/b.pdf', 'name' => 'Over https', 'group' => 'G'),
    array('url' => 'http://evil.example/c.pdf', 'name' => 'Off site', 'group' => 'G'),
)));
$put('nhym/index.json', array('built' => '2026-10-01', 'label' => 'NHYM', 'facilities' => array('9' => 2), 'operators' => array()));
$put('nhym/f/9.json', array('files' => array(
    array('url' => 'https://www.nhym-alumni.org/documents/a.pdf', 'name' => 'www host', 'group' => 'G'),
    array('url' => 'http://www.nhym-alumni.org/documents/b.pdf', 'name' => 'http', 'group' => 'G'),
)));
kop_survivor_archives_index(true);
$a = kop_survivor_archives('f', 9);
$by = array();
foreach ($a as $b) $by[$b['site']] = $b['count'];
$check('straights lists its http link only', ($by['straights'] ?? 0) === 1, json_encode($by));
$check('nhym lists its https www link only', ($by['nhym'] ?? 0) === 1, json_encode($by));
$check('straights index url is http', kop_survivor_archives_index()['straights']['url'] === 'http://thestraights.net/');
foreach (array('straights/f/9.json', 'straights/index.json', 'nhym/f/9.json', 'nhym/index.json') as $p) @unlink($fixture . '/' . $p);
foreach (array('straights/f', 'straights/o', 'nhym/f', 'nhym/o', 'straights', 'nhym') as $d) @rmdir($fixture . '/' . $d);
kop_survivor_archives_index(true);

// ---- SCIAD NET: Google Drive and Docs links, for that site alone; credit on its block ----
$check('hosts: drive and docs for sciad alone',
    kop_survivor_archives_hosts('sciad') === array('drive.google.com', 'docs.google.com')
    && kop_survivor_archives_hosts('ssi') === array('survivingstraightinc.com') && kop_survivor_archives_hosts('nope') === array());
mkdir($fixture . '/sciad/f', 0777, true);
mkdir($fixture . '/sciad/o', 0777, true);
$D = 'https://drive.google.com/file/d/';
$put('sciad/index.json', array('built' => '2026-10-01', 'label' => 'SCIAD NET, the WWASP Survivor Truth archive',
    'url' => 'https://evil.example/', 'facilities' => array('7' => 6), 'operators' => array('3' => 1)));
$put('sciad/f/7.json', array('files' => array(
    array('url' => $D . 'AAAAAAAAAAAAAAAAAAAAAAAAA/view', 'name' => 'State record, 2019-02-26', 'group' => 'DHS Records'),
    array('url' => 'https://docs.google.com/document/d/BBBBBBBBBBBBBBBBBBBBBBBBB/edit?usp=sharing', 'name' => 'Program brochure', 'group' => 'Program Documents'),
    array('url' => 'http://drive.google.com/file/d/CCCCCCCCCCCCCCCCCCCCCCCCC/view', 'name' => 'Over http', 'group' => 'DHS Records'),
    array('url' => 'https://drive.google.com.evil.example/file/d/x/view', 'name' => 'Look-alike host', 'group' => 'DHS Records'),
    array('url' => 'https://survivingstraightinc.com/a.pdf', 'name' => 'Another site', 'group' => 'DHS Records'),
    array('url' => $D . 'AAAAAAAAAAAAAAAAAAAAAAAAA/view', 'name' => 'Same file again', 'group' => 'Court records'),
)));
$put('sciad/o/3.json', array('files' => array(array('url' => $D . 'DDDDDDDDDDDDDDDDDDDDDDDDD/view', 'name' => 'Complaint, 2004', 'group' => 'Court records'))));
// A Drive link in another site's shard is off that site.
$put('ssi/f/8.json', array('files' => array(array('url' => $D . 'EEEEEEEEEEEEEEEEEEEEEEEEE/view', 'name' => 'Drive on ssi', 'group' => ''))));
$put('ssi/index.json', array('built' => '2026-10-01', 'label' => 'Surviving Straight Inc.',
    'facilities' => array('7' => 4, '8' => 1), 'operators' => array()));
kop_survivor_archives_index(true);
$check('sciad index url is the archive page, not the build', kop_survivor_archives_index()['sciad']['url'] === 'https://wwaspsurvivorstruth.com/program-archive/');
$check('another site keeps its home page', kop_survivor_archives_index()['ssi']['url'] === 'https://survivingstraightinc.com/');
$check('a Drive link on another site is dropped', kop_survivor_archives('f', 8) === array());
$a = kop_survivor_archives('f', 7);
$sc = null;
foreach ($a as $b) if ($b['site'] === 'sciad') $sc = $b;
$check('sciad lists its https Drive and Docs links once each', $sc && $sc['count'] === 2
    && array_column($sc['groups'], 'group') === array('DHS Records', 'Program Documents'), $sc ? $sc['count'] . ' files' : 'none');
$html = kop_survivor_archives_render($a, 'Program & Co');
$check('sciad block credits the archive, linked',
    strpos($html, '<h3 class="kop-fp-subhead">From <a href="https://wwaspsurvivorstruth.com/program-archive/" target="_blank" rel="noopener">SCIAD NET, the WWASP Survivor Truth archive</a></h3>') !== false
    && strpos($html, '2 documents about Program &amp; Co that Kids Over Profits does not hold a copy of. SCIAD NET keeps them on Google Drive') !== false);
$check('the other sites keep their own heading', strpos($html, '<h3 class="kop-fp-subhead">From Surviving Straight Inc.</h3>') !== false
    && strpos($html, 'Surviving Straight Inc. published and Kids Over Profits') !== false);
$check('sciad documents link to Drive', strpos($html, 'href="' . $D . 'AAAAAAAAAAAAAAAAAAAAAAAAA/view"') !== false
    && strpos($html, 'CCCCCCCCCCCCCCCCCCCCCCCCC') === false && strpos($html, 'evil.example') === false);
$o = array_values(array_filter(kop_survivor_archives('o', 3), function ($b) { return $b['site'] === 'sciad'; }));
$check('sciad lists an operator', $o && $o[0]['count'] === 1);
// A record with hundreds of documents: every group folds, each file listed once.
$many = array();
for ($i = 0; $i < 600; $i++) {
    $many[] = array('url' => $D . sprintf('F%024d', $i) . '/view', 'name' => 'Doc ' . $i, 'group' => $i % 2 ? 'Court records' : 'DHS Records');
}
$put('sciad/f/7.json', array('files' => $many));
$put('sciad/index.json', array('built' => '2026-10-01', 'label' => 'SCIAD NET, the WWASP Survivor Truth archive', 'facilities' => array('7' => 600), 'operators' => array()));
kop_survivor_archives_index(true);
$t0 = microtime(true);
$a = kop_survivor_archives('f', 7);
$html = kop_survivor_archives_render($a, 'Big');
$ms = (microtime(true) - $t0) * 1000;
$check('600 documents: two folded groups, 600 links, fast', substr_count($html, '<details class="kop-unsilenced-group">') >= 2
    && substr_count($html, 'drive.google.com/file/d/F') === 600 && $ms < 500, sprintf('%.0f ms', $ms));
foreach (array('sciad/f/7.json', 'sciad/o/3.json', 'sciad/index.json', 'ssi/f/8.json') as $p) @unlink($fixture . '/' . $p);
foreach (array('sciad/f', 'sciad/o', 'sciad') as $d) @rmdir($fixture . '/' . $d);
kop_survivor_archives_index(true);

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
    $named = array();
    foreach (array('facilities' => 'f', 'operators' => 'o') as $k => $dir) {
        foreach ((array) ($raw[$k] ?? array()) as $id => $n) {
            $shard = json_decode((string) @file_get_contents("{$real}/{$site}/{$dir}/{$id}.json"), true);
            if (!is_array($shard) || count($shard['files'] ?? array()) !== (int) $n) $bad[] = "{$dir}/{$id}";
            foreach ((array) ($shard['files'] ?? array()) as $f) {
                if (!in_array(preg_replace('/^www\./', '', strtolower((string) parse_url($f['url'] ?? '', PHP_URL_HOST))), kop_survivor_archives_hosts($site), true)
                    || strtolower((string) parse_url($f['url'], PHP_URL_SCHEME)) !== kop_survivor_archives_scheme($site)) $off[] = $f['url'] ?? '?';
                // SCIAD court records carry made-up neutral titles: never a case name ("X v. Y").
                if ($site === 'sciad' && substr((string) ($f['group'] ?? ''), -13) === 'Court records'
                    && preg_match('/\b(v\.?|vs\.?|versus)\s/i', (string) ($f['name'] ?? ''))) $named[] = $dir . '/' . $id;
            }
        }
    }
    $check("build {$site}: every indexed shard is there with its count", !$bad,
        $bad ? implode(', ', array_slice($bad, 0, 5)) : count($raw['facilities']) . ' facilities, ' . count($raw['operators']) . ' operators');
    $check("build {$site}: every link is on " . implode(' or ', kop_survivor_archives_hosts($site)), !$off, $off ? implode(', ', array_slice($off, 0, 3)) : '');
    if ($site === 'sciad') $check('build sciad: no title or group reads like a case name', !$named, implode(', ', array_slice(array_unique($named), 0, 5)));
}

// Clean up the fixture.
foreach (array('ssi/f/7.json', 'ssi/f/8.json', 'ssi/index.json', 'wwasp/f/7.json', 'wwasp/o/3.json', 'wwasp/o/4.json', 'wwasp/index.json') as $p) @unlink($fixture . '/' . $p);
foreach (array('ssi/f', 'ssi/o', 'wwasp/f', 'wwasp/o', 'ssi', 'wwasp', '') as $d) @rmdir($fixture . '/' . $d);

echo $failures ? "\n{$failures} failed.\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
