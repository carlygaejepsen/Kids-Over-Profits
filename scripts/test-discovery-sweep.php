<?php
/**
 * The facility sweep of scripts/discover-articles.php, offline: which names
 * are queried and how (batched or single), the request strings, the cursor,
 * the state file upgrade, and the filter on real headlines (the Desert Willow
 * closure stories, the "People"/"RISE" noise), then the AI confirmation of a
 * batched hit with a made-up AI answer. No network, no files written.
 *
 *   php scripts/test-discovery-sweep.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
define('KOP_DISCOVERY_NO_MAIN', true);
$argv = array($argv[0]);   // no flags leak into the script's defines
require __DIR__ . '/discover-articles.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

$queries = load_discovery_queries();
check('batch window defaults to when:3d', $queries['facilityBatchRecency'] === 'when:3d', $queries['facilityBatchRecency']);

/* ---- A made-up facilities feed -------------------------------------- */

$fac = function ($name, $loc, $status = 'Open', $past = array(), $other = array()) {
    return array(
        'identification' => array('currentName' => $name, 'name' => $name, 'pastNames' => $past, 'otherNames' => $other),
        'operatingPeriod' => array('status' => $status),
        'location' => $loc,
    );
};
$feed = array('projects' => array(
    array('category' => 'companies', 'name' => 'Acadia Healthcare', 'data' => array(
        'operator' => array('name' => 'Acadia Healthcare', 'status' => 'Active', 'websites' => array('https://acadiahealthcare.com/')),
        'facilities' => array(
            $fac('Desert Willow Treatment Center', 'Las Vegas, NV'),
            $fac('Sedona Sky Academy', 'Rimrock, AZ', 'Open', array('Copper Canyon Academy')),
            $fac('Provo Canyon School', 'Provo, UT', 'Closed'),
        ),
    )),
    array('category' => 'locations', 'name' => 'Wisconsin', 'data' => array(
        'facilities' => array(
            $fac('Grafton', 'Grafton, WI'),
            $fac('Day One', 'Portland, ME'),
            $fac('RISE', ''),
            $fac('People', 'Peoria, IL'),
            $fac('Juvenile Detention Center', 'Lincoln, NE'),
            $fac('Macon Youth Development Campus', 'Macon, GA', 'Closed'),
            $fac('New Hope Youth Center', 'Rockford, IL', 'Transferred'),
            $fac("A Mother's Love", 'Las Vegas, NV'),
            $fac('A Caring Heart', 'Reno, NV'),
            $fac('Pathway of Madison County', 'Owens Cross Roads, AL'),
        ),
    )),
));
$index = build_facility_index($feed, $queries['ignoreAliases'], $queries['genericAliasWords']);
$facilityIndex = $index['facilities'];
$names = array_map(static fn($f) => $f['queryName'], $facilityIndex);
check('closed and transferred records are in the index', in_array('Provo Canyon School', $names, true) && in_array('New Hope Youth Center', $names, true));
check('"People" is ignored', !in_array('People', $names, true));

$lines = build_sweep_names($facilityIndex, $queries, $index['genericAliases']);
$byKey = array();
foreach ($lines as $l) $byKey[$l['key']] = $l;
check('a past name is its own sweep line', isset($byKey['copper canyon academy']) && $byKey['copper canyon academy']['mode'] === 'batch');
check('the past name points at its record', isset($byKey['copper canyon academy']) && $facilityIndex[$byKey['copper canyon academy']['entries'][0]]['queryName'] === 'Sedona Sky Academy');
check('a closed program is batched', isset($byKey['provo canyon school']) && $byKey['provo canyon school']['mode'] === 'batch');
check('a one-word name with a state is single', isset($byKey['grafton']) && $byKey['grafton']['mode'] === 'single' && $byKey['grafton']['state'] === 'WI');
check('a two-word name is single', isset($byKey['day one']) && $byKey['day one']['mode'] === 'single');
check('a one-word name with no state is dropped', !isset($byKey['rise']));
check('a generic name is not searched', !isset($byKey['juvenile detention center']));
check('an everyday phrase of three words is single, not batched', isset($byKey['a mother s love']) && $byKey['a mother s love']['mode'] === 'single');
check('a two-word everyday phrase is single with its state', isset($byKey['a caring heart']) && $byKey['a caring heart']['mode'] === 'single');
check('a name three records share is not searched', !isset($byKey['juvenile detention center']));
check('a four-word name with one distinctive word is batched', isset($byKey['pathway of madison county']) && $byKey['pathway of madison county']['mode'] === 'batch');
check('a four-word name of common words is batched too', isset($byKey['new hope youth center']) && $byKey['new hope youth center']['mode'] === 'batch');
check('the company is batched', isset($byKey['acadia healthcare']) && $byKey['acadia healthcare']['mode'] === 'batch' && $byKey['acadia healthcare']['bucket'] === 'operator');
$keys = array_keys($byKey);
$sorted = $keys; sort($sorted, SORT_STRING);
check('lines are in key order', $keys === $sorted);

$requests = build_sweep_requests($lines);
$batches = array_values(array_filter($requests, static fn($r) => $r['mode'] === 'batch'));
$singles = array_values(array_filter($requests, static fn($r) => $r['mode'] === 'single'));
check('8 batched names (27 words) make two requests within 16 words each, 4 + 4', count($batches) === 2 && count($batches[0]['lines']) === 4 && count($batches[1]['lines']) === 4, count($batches) . ' batches: ' . implode(' / ', array_map(static fn($b) => count($b['lines']), $batches)));
echo '     modes: ' . implode(', ', array_map(static fn($l) => $l['key'] . '=' . $l['mode'], $lines)) . "\n";
foreach ($batches as $b) {
    $w = 0;
    foreach ($b['lines'] as $l) $w += count(explode(' ', $l['key']));
    check('a batch holds at most ' . GN_BATCH_WORDS . ' words of names', $w <= GN_BATCH_WORDS, "$w words");
}
check('four single requests: Grafton, Day One, A Mother\'s Love, A Caring Heart', count($singles) === 4, count($singles) . ' singles');
check('batches come before singles', $requests[0]['mode'] === 'batch');

$q = batch_news_query(array_map(static fn($l) => $l['name'], $batches[0]['lines']), $queries);
check('batch query: window first, then names OR\'d in parentheses', str_starts_with($q, 'when:3d ("') && str_contains($q, '" OR "') && str_contains($q, '") ('));
check('batch query: the short keyword list', str_contains($q, '(abuse OR lawsuit OR arrested OR investigation OR closure OR allegations OR death OR escape OR settlement)') && !str_contains($q, 'trafficking'));
$termCount = count(array_filter(preg_split('/\s+/', $q), static fn($w) => $w !== 'OR'));
check('batch query stays under Google\'s 32 words (operators aside)', $termCount <= 32, "$termCount words");
check('batch query: no state', !str_contains($q, '"Nevada"'));
check('batch query fits a URL', strlen(google_news_search_url($q)) < 1200, strlen(google_news_search_url($q)) . ' chars');
$sq = facility_news_query(array('queryName' => 'Grafton', 'state' => 'WI'), $queries);
check('single query: window, name and state', str_starts_with($sq, 'when:30d "Grafton" "Wisconsin" (') && str_contains($sq, 'trafficking)'));

/* ---- Cursor ---------------------------------------------------------- */

$ten = array_fill(0, 10, array('mode' => 'batch', 'lines' => array()));
foreach ($ten as $i => &$r) $r['n'] = $i;
unset($r);
list($s1, $c1) = sweep_slice($ten, 0);
check('half the list per run', count($s1) === 5 && $c1 === 5 && $s1[0]['n'] === 0 && $s1[4]['n'] === 4);
list($s2, $c2) = sweep_slice($ten, $c1);
check('the other half next run, wrapping to 0', count($s2) === 5 && $s2[0]['n'] === 5 && $c2 === 0);
list($s3, $c3) = sweep_slice($ten, 8);
check('wraps around the end', $s3[0]['n'] === 8 && $s3[2]['n'] === 0 && $c3 === 3);
list($s4, $c4) = sweep_slice($ten, 3, 2);
check('--max-facilities caps the requests', count($s4) === 2 && $c4 === 5);
list($s5) = sweep_slice(array(), 7);
check('an empty list is fine', $s5 === array());

/* ---- State file upgrade ----------------------------------------------- */

$tmp = tempnam(sys_get_temp_dir(), 'kopstate');
file_put_contents($tmp, json_encode(array('version' => 2, 'seenUrls' => array('a'), 'pending' => array(), 'stats' => array('discovered' => 1, 'submitted' => 0, 'rejected' => 0))));
$loaded = json_decode(file_get_contents($tmp), true);
// load_state() reads STATE_FILE; apply its upgrade rule to the parsed copy the same way.
$up = (function (array $s) {
    if (!isset($s['sweepCursor']) || !is_int($s['sweepCursor']) || $s['sweepCursor'] < 0) $s['sweepCursor'] = 0;
    if (!isset($s['sweptAt']) || !is_array($s['sweptAt'])) $s['sweptAt'] = array();
    $s['version'] = 3;
    return $s;
})($loaded);
check('a version-2 state gets a cursor at 0', $up['sweepCursor'] === 0 && $up['sweptAt'] === array() && $up['version'] === 3);
unlink($tmp);
$fresh = load_state();
check('load_state() gives a cursor and sweptAt', isset($fresh['sweepCursor'], $fresh['sweptAt']) && $fresh['version'] === 3);

/* ---- The filter on real headlines ------------------------------------- */

$blacklist = build_blacklist_matcher();
$sweepIndex = array();
foreach ($lines as $l) $sweepIndex[$l['key']] = $l['entries'];
$opts = array('rejectHeadlines' => $queries['rejectHeadlines'], 'sweepIndex' => $sweepIndex);
$batchNames = array_map(static fn($l) => $l['name'], $batches[0]['lines']);
$batchKeys = array_map(static fn($l) => $l['key'], $batches[0]['lines']);
$item = function ($title, $mode, $names, $keys, $host = 'example.com') {
    return array(
        'title' => $title, 'description' => $title, 'link' => "https://{$host}/news/" . md5($title), 'pubDate' => '',
        'sourceUrl' => "https://{$host}", 'sourceName' => $host, 'origin' => 'google-news',
        'facilityQuery' => implode(' | ', $names), 'sweepMode' => $mode, 'batchNames' => $names, 'batchKeys' => $keys,
        'facilityState' => '', 'facilityCity' => '',
    );
};

$r = evaluate_candidate($item('Nevada officials close youth mental hospital for urgent overhaul, citing patient safety - The Nevada Independent', 'batch', $batchNames, $batchKeys, 'thenevadaindependent.com'),
    $facilityIndex, $blacklist, $index['ownHosts'], $index['genericAliases'], $opts);
check('Desert Willow story (name only in the body) is kept, attribution pending', $r['accept'] && ($r['attribution'] ?? '') === 'pending', json_encode($r));
check('...and says which names it was searched for', in_array('batch:' . implode('|', $batchNames), $r['reasons'], true));

$r = evaluate_candidate($item('Judge denies qualified immunity to Provo Canyon School staff in abuse lawsuit - WyoFile', 'batch', $batchNames, $batchKeys, 'wyofile.com'),
    $facilityIndex, $blacklist, $index['ownHosts'], $index['genericAliases'], $opts);
check('a batched hit naming the program is attributed at once', $r['accept'] && !isset($r['attribution']) && ($r['match']['facility'] ?? '') === 'Provo Canyon School', json_encode($r));

$r = evaluate_candidate($item('Acadia Healthcare (ACHC) Upgraded to Buy - Yahoo Finance', 'batch', $batchNames, $batchKeys, 'finance.yahoo.com'),
    $facilityIndex, $blacklist, $index['ownHosts'], $index['genericAliases'], $opts);
check('stock coverage of a company is blocked', !$r['accept'] && $r['reason'] === 'blacklist-host', json_encode($r));

$r = evaluate_candidate($item('2 Grafton men charged with animal cruelty after 11 neglected dogs rescued - Fox6', 'single', array('Grafton'), array('grafton'), 'fox6now.com'),
    $facilityIndex, $blacklist, $index['ownHosts'], $index['genericAliases'], $opts);
check('a single-name hit with the town but not the program is rejected', !$r['accept'] && $r['reason'] === 'facility-unmatched', json_encode($r));

$r = evaluate_candidate($item('Teen stabbed to death by co-worker on his day off - New York Post', 'single', array('Day One'), array('day one'), 'nypost.com'),
    $facilityIndex, $blacklist, $index['ownHosts'], $index['genericAliases'], $opts);
check('a single-name hit naming nothing is rejected', !$r['accept'] && $r['reason'] === 'facility-unmatched');

$r = evaluate_candidate($item('Transnational online scam operations on the rise, U.S. State Department says', 'batch', array('RISE'), array('rise'), 'example.com'),
    $facilityIndex, $blacklist, $index['ownHosts'], $index['genericAliases'], $opts);
check('a batched hit for a name the sweep index lacks still goes to the AI, not the bin', $r['accept'] && ($r['attribution'] ?? '') === 'pending');

/* ---- AI confirmation --------------------------------------------------- */

$cand = $item('Nevada officials close youth mental hospital for urgent overhaul', 'batch', $batchNames, $batchKeys);
$m = confirm_batch_attribution($cand, array(
    'title' => 'Nevada officials close youth mental hospital for urgent overhaul',
    'facilities' => array('Desert Willow Treatment Center'),
    'summary' => 'The state closed the Las Vegas hospital for children...', 'location' => 'Las Vegas, NV',
), $facilityIndex, $sweepIndex);
check('the AI naming Desert Willow confirms it', $m !== null && $m['facility']['queryName'] === 'Desert Willow Treatment Center');

$m = confirm_batch_attribution($cand, array(
    'title' => 'Scam operations on the rise', 'facilities' => array(), 'summary' => 'Nothing about any program.', 'location' => 'Washington, DC',
), $facilityIndex, $sweepIndex);
check('the AI naming none of the batch leaves it unconfirmed', $m === null);

$m = confirm_batch_attribution($cand, array(
    'title' => 'Former Copper Canyon Academy students sue', 'facilities' => array('Copper Canyon Academy'), 'summary' => '', 'location' => '',
), $facilityIndex, $sweepIndex);
check('a past name in the AI answer confirms its record', $m !== null && $m['facility']['queryName'] === 'Sedona Sky Academy');

$m = confirm_batch_attribution($cand, array('title' => 'Grafton man arrested', 'facilities' => array('Grafton'), 'summary' => '', 'location' => ''), $facilityIndex, $sweepIndex);
check('a program outside the request\'s names does not confirm', $m === null);

/* ---- Request carries what the filter needs ----------------------------- */

$req = $requests[0];
$fake = array('items' => array(array('title' => 'x', 'link' => 'https://news.google.com/rss/articles/abc', 'pubDate' => '', 'description' => '', 'sourceUrl' => '', 'sourceName' => '')), 'failed' => false);
// fetch_google_news_for_request goes to the network; check the shape its map adds through a copy of the merge.
$names = array_map(static fn($l) => $l['name'], $req['lines']);
$merged = array_merge($fake['items'][0], array('origin' => 'google-news', 'facilityQuery' => implode(' | ', $names), 'sweepMode' => $req['mode'],
    'batchNames' => $names, 'batchKeys' => array_map(static fn($l) => $l['key'], $req['lines'])));
check('a request item names every program it searched', count($merged['batchNames']) === count($req['lines']) && count($req['lines']) > 1 && $merged['sweepMode'] === 'batch' && str_contains($merged['facilityQuery'], ' | '));

echo $failures ? "\n{$failures} FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
