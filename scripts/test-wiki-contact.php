<?php
/**
 * Offline test for api/lib-wiki-contact.php: the program wiki entries' contact
 * becomes the r/troubledteens modmail, and each entry is compared with its
 * Reddit wiki page.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-contact.php [--list]
 *
 * Copies wiki_submissions from tmp/prod.sqlite into memory, runs the migration
 * twice (the second run must change nothing), checks no old handle is left
 * and every json_data still decodes, checks the PHP rewrite gives the same
 * text as normalizeContactTag() in js/wiki-generation.js (needs node), and
 * prints how many entries differ from their Reddit page. --list names them.
 * Nothing is written to tmp/prod.sqlite.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
require $root . '/api/lib-wiki-contact.php';

$fail = 0;
$check = function ($ok, $label) use (&$fail) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fail++;
};

// --- 1. the rewrite on fixtures ------------------------------------------------
$link = kop_wiki_contact_link();
$cases = array(
    'please contact [u/Signal-Strain9810](/u/Signal-Strain9810).' => "please contact $link.",
    'please contact [u/Miss_Nobody89](/u/Miss_Nobody89).'         => "please contact $link.",
    'please contact [u/Signal-Strain8910](/u/Signal-Strain8910) .' => "please contact $link .",
    'please contact u/Signal-Strain8910.'                          => "please contact $link.",
    'contact /user/Miss_Nobody89/ today'                           => "contact $link today",
    "please contact $link."                                        => "please contact $link.",
    'Link to [u/Greedy_Guarantee_166\'s testimony](https://reddit.com/x)' => 'Link to [u/Greedy_Guarantee_166\'s testimony](https://reddit.com/x)',
);
foreach ($cases as $in => $want) {
    $check(kop_wiki_contact_normalize($in) === $want, 'rewrite: ' . $in);
}
// --- 2. PHP == JS ----------------------------------------------------------------
$node = trim((string) shell_exec('node --version 2>&1'));
if (preg_match('/^v\d+/', $node)) {
    $inputs = array_keys($cases);
    $tmp = tempnam(sys_get_temp_dir(), 'kopwc');
    file_put_contents($tmp, json_encode($inputs));
    $js = 'const {normalizeContactTag}=require(' . json_encode($root . '/js/wiki-generation.js') . ');'
        . 'const a=JSON.parse(require("fs").readFileSync(' . json_encode($tmp) . ',"utf8"));'
        . 'process.stdout.write(JSON.stringify(a.map(normalizeContactTag)));';
    $script = $tmp . '.js';
    file_put_contents($script, $js);
    $out = json_decode((string) shell_exec('node ' . escapeshellarg($script)), true);
    unlink($tmp);
    unlink($script);
    $same = is_array($out) && count($out) === count($inputs);
    foreach ($inputs as $i => $in) {
        if (!$same || $out[$i] !== kop_wiki_contact_normalize($in)) { $same = false; break; }
    }
    $check($same, 'PHP rewrite == normalizeContactTag() in js/wiki-generation.js');
} else {
    echo "skip PHP == JS (no node)\n";
}

// --- 3. the real rows -------------------------------------------------------------
$db = $root . '/tmp/prod.sqlite';
if (!is_file($db)) {
    echo "skip real rows (no tmp/prod.sqlite)\n";
    exit($fail ? 1 : 0);
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($db) . ' AS prod');
$pdo->exec('CREATE TABLE wiki_submissions AS SELECT * FROM prod.wiki_submissions');
$pdo->exec('DETACH DATABASE prod');

$old = '~Miss_Nobody89|Signal-Strain(?:9810|8910)~';
$count_old = function () use ($pdo, $old) {
    $n = 0;
    foreach ($pdo->query('SELECT generated_markdown, original_markdown, json_data FROM wiki_submissions') as $r) {
        foreach ($r as $v) if (is_string($v) && preg_match($old, $v)) $n++;
    }
    return $n;
};
$before_updated = $pdo->query('SELECT id, updated_at FROM wiki_submissions')->fetchAll(PDO::FETCH_KEY_PAIR);
echo 'fields naming an old handle: ' . $count_old() . "\n";
$dry = kop_wiki_contact_migrate($pdo, false);
$first = kop_wiki_contact_migrate($pdo, true);
echo "migration: {$first['rows']} rows, {$first['fields']} fields\n";
$check(empty($first['error']), 'migration ran without error' . (empty($first['error']) ? '' : ': ' . $first['error']));
$check($dry['rows'] === $first['rows'], 'dry run counts what the run changes');
$check($count_old() === 0, 'no field names an old handle');
$second = kop_wiki_contact_migrate($pdo, true);
$check($second['rows'] === 0, 'second run changes nothing');
$bad_json = 0;
foreach ($pdo->query("SELECT json_data FROM wiki_submissions WHERE json_data IS NOT NULL AND json_data != ''") as $r) {
    if (!is_array(json_decode($r['json_data'], true))) $bad_json++;
}
$check($bad_json === 0, 'every json_data decodes');
$check($before_updated === $pdo->query('SELECT id, updated_at FROM wiki_submissions')->fetchAll(PDO::FETCH_KEY_PAIR), 'updated_at untouched');
$with_link = (int) $pdo->query("SELECT COUNT(*) FROM wiki_submissions WHERE generated_markdown LIKE '%message/compose?to=/r/troubledteens%'")->fetchColumn();
echo "entries whose text now names modmail: $with_link\n";

// --- 4. against Reddit (scripts/reddit-wiki-live.py's result) ----------------------
$fixture = tempnam(sys_get_temp_dir(), 'kopwl');
file_put_contents($fixture, json_encode(array(
    'checked' => '2026-10-05',
    'pages' => array('index/same' => array('exists' => true, 'revised' => '2022-03-08', 'fetched' => '2026-10-05'),
                     'index/diff' => array('exists' => true, 'revised' => '2023-01-01', 'fetched' => '2026-10-05'),
                     'index/gone' => array('exists' => false, 'revised' => '', 'fetched' => '2026-10-05'),
                     'active-programs/cedu' => array('exists' => true, 'revised' => '2021-01-01', 'fetched' => '2026-10-05')),
    'rows' => array('1' => array('page' => 'index/same', 'updated_at' => '2026-06-08 19:38:15', 'edited' => false, 'differs' => false, 'samples' => array()),
                    '2' => array('page' => 'index/diff', 'updated_at' => '2026-06-08 19:38:15', 'edited' => true, 'differs' => true, 'samples' => array(array('reddit' => 'a', 'ours' => 'b'))),
                    '3' => array('page' => 'index/gone', 'updated_at' => '2026-06-08 19:38:15', 'edited' => false, 'differs' => true, 'samples' => array()),
                    '4' => array('page' => 'active-programs/cedu', 'updated_at' => '2026-06-08 19:38:15', 'edited' => false, 'differs' => false, 'samples' => array())),
)));
define('KOP_WIKI_REDDIT_LIVE_FILE', $fixture);
$at = '2026-06-08 19:38:15';
$state = function ($id, $updated) { $c = kop_wiki_reddit_compare(array('id' => $id, 'updated_at' => $updated)); return $c ? $c['state'] : null; };
$check($state(1, $at) === 'same', 'matching entry reads same');
$check($state(2, $at) === 'differs', 'differing entry reads differs');
$check($state(3, $at) === 'missing', 'entry whose page is gone reads missing');
$check($state(1, '2026-10-06 10:00:00') === 'changed', 'entry saved after the check reads changed, not same');
$check($state(99, $at) === null, 'entry the check never reached gets no mark');
$check(strpos(kop_wiki_reddit_compare(array('id' => 3, 'updated_at' => $at))['reddit_url'], '/gone') === false, 'missing page links the wiki index, not a dead page');
$nested = kop_wiki_reddit_compare(array('id' => 4, 'updated_at' => $at));
$check($nested['reddit_url'] === 'https://www.reddit.com/r/troubledteens/wiki/active-programs/cedu/' && $nested['slug'] === 'active-programs/cedu', 'a page outside index/ links its own address');
$check(kop_wiki_reddit_compare(array('id' => 1, 'updated_at' => $at))['slug'] === 'same', 'an index page is keyed without index/, as the editor names it');
$all = kop_wiki_reddit_compare_all($pdo);
echo 'compare_all on the mirror with the fixture: ' . count($all) . " slugs\n";
unlink($fixture);

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit($fail ? 1 : 0);
