<?php
/**
 * inc/name-variants.php on the real people table (tmp/prod.sqlite): every merge and other name becomes a group, the
 * reviewed names join, "Not the same" pairs come through. Writes tmp/name-variants.json (what the wiki editor gets),
 * which node scripts/test-wiki-generation-rules.js then reads.
 *
 *     php -d extension=pdo_sqlite scripts/test-name-variants.php
 */
define('ABSPATH', __DIR__ . '/');
$root = dirname(__DIR__);
require $root . '/inc/name-variants.php';

$bad = 0;
$check = function ($what, $ok) use (&$bad) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) $bad++;
};

$db = $root . '/tmp/prod.sqlite';
if (!is_file($db)) {
    echo "no tmp/prod.sqlite: sync the mirror first (scripts/sync-prod-sqlite.py)\n";
    exit(1);
}
$pdo = new PDO('sqlite:' . $db);
$people = $pdo->query('SELECT id, name, aliases, merged_into FROM wpdl_kop_people')->fetchAll(PDO::FETCH_ASSOC);
$opt = function ($name) use ($pdo) {
    $st = $pdo->prepare('SELECT option_value FROM wpdl_options WHERE option_name = ?');
    $st->execute(array($name));
    $v = $st->fetchColumn();
    $u = $v === false ? array() : @unserialize($v);
    return is_array($u) ? $u : array();
};
$graph = json_decode((string) file_get_contents($root . '/js/data/network/graph.json'), true);
$reviewed = json_decode((string) file_get_contents($root . '/js/data/people/name-variants-reviewed.json'), true);
$nick = json_decode((string) file_get_contents($root . '/js/data/people/nicknames.json'), true);

$data = kop_name_variants_build($people, $opt('kop_people_merge_dismissed'), (array) ($graph['nodes'] ?? array()), $reviewed);
$data['nicknames'] = array('same' => $nick['same'], 'maybe' => $nick['maybe']);

$in_group = function ($a, $b) use ($data) {
    foreach ($data['groups'] as $g) {
        if (in_array($a, $g, true) && in_array($b, $g, true)) return true;
    }
    return false;
};
// Every merge the owner made at Merge People is one group.
$log = $opt('kop_people_merge_log');
$merges = 0;
$missed = array();
foreach ($log as $m) {
    $keep = (string) ($m['keep']['name'] ?? '');
    $drop = (string) ($m['drop']['name'] ?? '');
    if ($keep === '' || $drop === '' || kop_name_variants_key($keep) === kop_name_variants_key($drop)) continue;
    $merges++;
    if (!$in_group($keep, $drop)) $missed[] = "$keep / $drop";
}
$check("every Merge People merge is a group ($merges merges" . ($missed ? '; missing: ' . implode(', ', array_slice($missed, 0, 5)) : '') . ')', !$missed);
// Every other name typed on a person is in their group.
$alias_missed = array();
foreach ($people as $p) {
    foreach (preg_split('/\r\n|\r|\n/', (string) $p['aliases']) as $a) {
        $a = trim($a);
        if ($a === '' || (int) $p['merged_into'] > 0 || kop_name_variants_key($a) === kop_name_variants_key($p['name'])) continue;
        if (!$in_group($p['name'], $a)) $alias_missed[] = "{$p['name']} / $a";
    }
}
$check('every other name is in its person\'s group' . ($alias_missed ? ': missing ' . implode(', ', array_slice($alias_missed, 0, 5)) : ''), !$alias_missed);
$check('the reviewed names are groups (Kovaleski, Spanos, Nale Fakahua)', $in_group('Tom Kovaleski', 'Tom Kovalesky')
    && $in_group('Jerry Spanos', 'Gerald Spanos') && $in_group('Nale Fakahua', 'Salesi Misinale Fakahua'));
$check('Oscar and Nale Fakahua are two people', in_array(array('Oscar Fakahua', 'Nale Fakahua'), $data['distinct'], true) && !$in_group('Oscar Fakahua', 'Nale Fakahua'));
$check('"Not the same" pairs come through (' . count($opt('kop_people_merge_dismissed')) . ')', count($data['distinct']) >= count($opt('kop_people_merge_dismissed')));
$keys_ok = true;
foreach ($data['groups'] as $g) {
    if (count(array_unique(array_map('kop_name_variants_key', $g))) < 2) $keys_ok = false;
}
$check('no group is only one key', $keys_ok);

@mkdir($root . '/tmp', 0777, true);
file_put_contents($root . '/tmp/name-variants.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
printf("%d groups, %d distinct pairs, %d nickname groups -> tmp/name-variants.json (%d KB)\n", count($data['groups']), count($data['distinct']),
    count($data['nicknames']['same']) + count($data['nicknames']['maybe']), filesize($root . '/tmp/name-variants.json') / 1024);
echo $bad ? "$bad failed\n" : "all passed\n";
exit($bad ? 1 : 0);
