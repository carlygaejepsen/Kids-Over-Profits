<?php
/**
 * Offline checks for the Map Renames screen (inc/network-renames.php) on the
 * real js/data/network/graph.json: every rebrand line listed with both names'
 * years and operators, the problems it flags, the year it suggests, how a
 * saved rename splits the names' years, and a swapped line.
 *
 *   php scripts/test-network-renames.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$db_path = dirname(__DIR__) . '/tmp/prod.sqlite';
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
require __DIR__ . '/kop-test-harness.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$graph = kop_network_map_graph();
$base = kop_network_renames_base_years($graph);
$rows = kop_network_renames_list($graph, $base);
$by = array();
foreach ($rows as $r) $by[$r['key']] = $r;
$rebrands = 0;
foreach ($graph['edges'] as $e) if (in_array('rebrand', (array) ($e['roles'] ?? array()), true)) $rebrands++;
$check('every rebrand line is listed', count($rows) === $rebrands, count($rows) . ' of ' . $rebrands);

echo "-- Copper Canyon -> Sedona Sky --\n";
$cc = $by['copper-canyon-academy>sedona-sky-academy'] ?? null;
$check('the pair is listed in the board\'s direction', $cc !== null);
$check('each name carries its own operator', $cc && in_array('Aspen Education Group (affiliated)', $cc['earlier']['operators'], true)
    && (bool) array_filter($cc['later']['operators'], static function ($o) { return stripos($o, 'Family Help') === 0; }),
    $cc ? implode('; ', $cc['earlier']['operators']) . ' | ' . implode('; ', $cc['later']['operators']) : '');
$a = kop_network_renames_assess($cc);
$check('the overlap is flagged', in_array('overlap', $a['flags'], true), implode(',', $a['flags']));
$check('2014 is suggested, from the year Copper Canyon closed', $a['suggest'] === 2014, (string) $a['suggest']);
$years = kop_network_renames_apply_years($base, array('copper-canyon-academy>sedona-sky-academy' => array('decision' => 'saved', 'year' => 2014)));
$check('saving 2014 splits the years: Copper Canyon 1998-2014, Sedona Sky from 2014',
    ($years['copper-canyon-academy'] ?? '') === '1998-2014' && ($years['sedona-sky-academy'] ?? '') === 'from 2014', json_encode($years));

echo "-- Flags across the board --\n";
$counts = array('same' => 0, 'overlap' => 0, 'missing' => 0, 'reversed' => 0);
$suggested = 0;
foreach ($rows as $r) {
    $x = kop_network_renames_assess($r);
    foreach ($x['flags'] as $f) $counts[$f]++;
    if ($x['suggest']) $suggested++;
}
echo '  ' . json_encode($counts) . ", a year suggested for $suggested of " . count($rows) . "\n";
$khk = null;
foreach ($rows as $r) if ($r['earlier']['name'] === 'KHK a Pathway Family Center' && $r['later']['name'] === 'Kids Helping Kids') $khk = $r;
$check('a line drawn the wrong way round is flagged reversed (KHK 2006 -> Kids Helping Kids 1982)',
    $khk && in_array('reversed', kop_network_renames_assess($khk)['flags'], true));
$nb = null;
foreach ($rows as $r) if ($r['earlier']['name'] === "New Beginnings Girls' Academy" && $r['later']['name'] === 'Rebekah Home for Girls') $nb = $r;
$check('so is one whose years only touch (New Beginnings 2001-2015 -> Rebekah Home 1967-2001)',
    $nb && in_array('reversed', kop_network_renames_assess($nb)['flags'], true));
$check('but Copper Canyon -> Sedona Sky is not', !in_array('reversed', kop_network_renames_assess($cc)['flags'], true));
$check('and no year is suggested for it', $khk && kop_network_renames_assess($khk)['suggest'] === null);
$agape = $by['agape-baptist-academy>agape-boarding-school'] ?? null;
$check('names given the same years are flagged', $agape && in_array('same', kop_network_renames_assess($agape)['flags'], true));
$check('and get no suggestion (nothing tells the rename year)', $agape && kop_network_renames_assess($agape)['suggest'] === null);

echo "-- Chains and swaps --\n";
$chain = kop_network_renames_apply_years(
    array('a' => '1990-2003', 'b' => '1990-2003', 'c' => '1990-2003'),
    array('a>b' => array('decision' => 'saved', 'year' => 1995), 'b>c' => array('decision' => 'saved', 'year' => 1999)));
$check('a chain of renames gives each name its stretch', $chain === array('a' => '1990-1995', 'b' => '1995-1999', 'c' => '1999-2003'), json_encode($chain));
$swap = kop_network_renames_apply_years(array('x' => '2006-2008', 'y' => '1982-1987'),
    array('x>y' => array('decision' => 'saved', 'year' => 1987, 'swapped' => true)));
$check('a swapped rename ends the other name', $swap === array('y' => '1982-1987', 'x' => '1987-2008') || $swap === array('x' => '1987-2008', 'y' => '1982-1987'), json_encode($swap));
$check('skipped and unsaved renames change nothing', kop_network_renames_apply_years($base,
    array('copper-canyon-academy>sedona-sky-academy' => array('decision' => 'skipped', 'year' => 0))) === array());
$open_after = kop_network_renames_apply_years(array('p' => '', 'q' => ''), array('p>q' => array('decision' => 'saved', 'year' => 2010)));
$check('names with no years get "until" and "from" the rename', $open_after === array('p' => 'until 2010', 'q' => 'from 2010'), json_encode($open_after));

list($nodes, $edges) = kop_network_map_apply_rename_flips(
    array(array('id' => 'khk', 'status' => 'rebranded'), array('id' => 'kids', 'status' => 'closed')),
    array(array('source' => 'khk', 'target' => 'kids', 'roles' => array('rebrand')), array('source' => 'khk', 'target' => 'z', 'roles' => array('staff'))),
    array('khk>kids'));
$check('a swap reverses the rename line only', $edges[0]['source'] === 'kids' && $edges[0]['target'] === 'khk' && $edges[1]['source'] === 'khk');
$check('and moves "rebranded" to the name that is now earlier', $nodes[0]['status'] === 'closed' && $nodes[1]['status'] === 'rebranded', json_encode($nodes));

echo "-- Researched years --\n";
$cands = kop_network_renames_candidates();
$check('the researched years load, one per rename line at most', count($cands) > 0 && !array_diff_key($cands, $by), count($cands) . ' candidates');
$cc = $cands['copper-canyon-academy>sedona-sky-academy'] ?? null;
$check('Copper Canyon -> Sedona Sky: 2014 from the sources, order as drawn', $cc && $cc['year'] === 2014 && !$cc['swapped']);
$bad = 0;
foreach ($cands as $c) {
    if (!$c['year']) continue;
    $q = implode(' ', array_column($c['sources'], 'quote'));
    // A year from the source's date is kept, but the card has to say so.
    if (strpos($q, (string) $c['year']) === false && strpos($c['note'], 'not in the quoted words') === false) $bad++;
}
$check('a year outside the quotes is kept with a note saying so', $bad === 0, $bad . ' bad');
$mt = $cands['montana-academy>embark-at-flathead-valley'] ?? null;
$check('Kids Over Profits reporting counts as a source: Montana Academy -> Embark 2022, high',
    $mt && $mt['year'] === 2022 && $mt['confidence'] === 'high' && stripos($mt['note'], 'circular') === false,
    $mt ? $mt['year'] . ' ' . $mt['confidence'] : '');

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
