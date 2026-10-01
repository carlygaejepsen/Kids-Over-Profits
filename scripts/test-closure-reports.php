<?php
/**
 * Offline checks for the news closure pipeline (inc/closure-reports.php) and
 * the network map's live status layer (kop_network_map_status_overrides() in
 * inc/network-map.php), against the production mirror in tmp/prod.sqlite.
 *
 *   php scripts/test-closure-reports.php [--db=tmp/prod.sqlite]
 *
 * No Groq calls: the model's reply is faked. The confirm and undo document
 * changes run on real facility documents and must pass the facility
 * validator; the writes themselves (MySQL locks) are not exercised here.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/closure-reports.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// ---------------------------------------------------------------------------
echo "-- Which articles go to Groq --\n";
$news = $pdo->query("SELECT id, article_title, summary, article_type, status FROM news_submissions")->fetchAll(PDO::FETCH_ASSOC);
$gated = array();
foreach ($news as $n) {
    if (!in_array($n['status'], array('rejected', 'deleted', 'promotional'), true) && kop_closure_article_mentions_closure($n)) {
        $gated[(int) $n['id']] = $n['article_title'];
    }
}
printf("  %d of %d articles pass the closure gate\n", count($gated), count($news));
$check('every article typed "closure" passes', !array_filter($news, static function ($n) {
    return $n['article_type'] === 'closure' && !kop_closure_article_mentions_closure($n);
}));
$check('"Asheville Academy closes after license surrender" passes', kop_closure_article_mentions_closure(array(
    'article_type' => 'general', 'article_title' => 'Asheville Academy closes after license surrender', 'summary' => '')));
$check('"Pearl Youth Residence to close in November" passes', kop_closure_article_mentions_closure(array(
    'article_type' => 'general', 'article_title' => 'Pearl Youth Residence to close in November', 'summary' => '')));
$check('a lawsuit article with no closure words is left out', !kop_closure_article_mentions_closure(array(
    'article_type' => 'lawsuit', 'article_title' => 'Former students sue academy over restraint', 'summary' => 'The suit alleges staff used prone restraint.')));
$check('"closet" and "disclosed" do not count', !kop_closure_article_mentions_closure(array(
    'article_type' => 'general', 'article_title' => 'Records disclosed', 'summary' => 'Children were locked in a closet.')));
$check('the hash moves when the summary does',
    kop_closure_news_hash(array('article_title' => 'a', 'summary' => 'b')) !== kop_closure_news_hash(array('article_title' => 'a', 'summary' => 'c')));

// ---------------------------------------------------------------------------
echo "-- Reading the model's reply --\n";
$linked = array(array('id' => 11, 'unique_name' => 'Alpha Academy', 'city' => 'X', 'state' => 'UT', 'status' => 'Open'));
$reply = json_encode(array('closures' => array(
    array('program' => 'Alpha Academy', 'facilityId' => 11, 'location' => 'X, UT', 'stage' => 'closed', 'date' => '2025-06-01', 'quote' => 'Alpha Academy closed its doors on June 1.'),
    array('program' => 'alpha academy', 'facilityId' => 11, 'stage' => 'closing'),
    array('program' => 'Beta Ranch', 'facilityId' => 99, 'stage' => 'ordered_closed', 'date' => 'June 2025'),
    array('program' => 'Gamma House', 'stage' => 'threatened'),
    array('program' => '', 'stage' => 'closed'),
    array('program' => 'Delta', 'stage' => 'SUSPENDED', 'date' => '1890'),
)));
$parsed = kop_closure_parse_reply("Here you go:\n```json\n" . $reply . "\n```", $linked);
$check('reply parses through a code fence', is_array($parsed));
$check('unknown stages and empty names dropped, repeats collapsed', array_column($parsed, 'program') === array('Alpha Academy', 'Beta Ranch', 'Delta'),
    implode(', ', array_column($parsed, 'program')));
$check('an id from the article\'s links is kept', $parsed[0]['facility_id'] === 11);
$check('an id the model was not given is cleared', $parsed[1]['facility_id'] === null);
$check('dates cleaned: ISO kept, prose and 1890 dropped', $parsed[0]['date'] === '2025-06-01' && $parsed[1]['date'] === '' && $parsed[2]['date'] === '');
$check('stage case folded', $parsed[2]['stage'] === 'suspended');
$check('no closures array is unreadable', kop_closure_parse_reply('{"answer":"none"}', $linked) === null);
$check('an empty list is a clean "none"', kop_closure_parse_reply('{"closures":[]}', $linked) === array());
$check('YYYY-MM kept', kop_closure_clean_date('2024-11') === '2024-11');
$check('closed facilities do not create another review alert',
    kop_closure_classify_report(11, 'Closed', 'Suspended') === 'already');
$check('an open facility still needs review',
    kop_closure_classify_report(11, 'Open', 'Closed') === 'pending');
$check('unmatched reports still need review',
    kop_closure_classify_report(null, null, 'Closed') === 'unmatched');

$text = 'LEBANON, Ind. - The Refuge Girls Academy closed last week, the ministry said. “We made the difficult decision,” a spokesperson wrote.';
$check('a copied quote is found', kop_closure_quote_found('The Refuge Girls Academy closed last week, the ministry said.', $text));
$check('a re-punctuated quote is found', kop_closure_quote_found('the refuge girls academy closed last week -- the ministry said', $text));
$check('a made-up quote is not', !kop_closure_quote_found('The Refuge Girls Academy was shut down by the state.', $text));
$check('a three-word quote is not enough', !kop_closure_quote_found('Refuge Girls Academy', $text));

$prompt = kop_closure_build_prompt(array('article_title' => 'T', 'publication_name' => 'P', 'publication_date' => '2025-01-02',
    'article_url' => 'https://example.org/a', 'summary' => 'S'), $linked, 'body text');
$check('prompt lists the linked facilities with ids', strpos($prompt, "11 | Alpha Academy | X, UT | Open") !== false);
$check('prompt carries the article text and the leave-out rules', strpos($prompt, 'body text') !== false && strpos($prompt, 'Leave out') !== false);

// ---------------------------------------------------------------------------
echo "-- Matching the article's name to a facility --\n";
require_once dirname(__DIR__) . '/api/facility-aliases.php';
$alias_index = null;
foreach (array('Asheville Academy for Girls', 'The Refuge Girls Academy', 'Pearl Youth Residence', 'Provo Canyon School') as $name) {
    $fid = kop_closure_resolve_facility($pdo, array('program' => $name, 'facility_id' => null), $alias_index);
    $row = $fid ? $pdo->query('SELECT unique_name, status FROM facilities_v2 WHERE id = ' . (int) $fid)->fetch(PDO::FETCH_ASSOC) : null;
    echo '  ' . $name . ' -> ' . ($row ? "#$fid {$row['unique_name']} ({$row['status']})" : 'no match') . "\n";
}
$fid = kop_closure_resolve_facility($pdo, array('program' => 'Asheville Academy for Girls', 'facility_id' => null), $alias_index);
$check('a facility named in an article resolves to facilities_v2', $fid !== null);
$check('a name nobody has stays unmatched', kop_closure_resolve_facility($pdo, array('program' => 'Zzyzx Imaginary Ranch For Teens', 'facility_id' => null), $alias_index) === null);

// ---------------------------------------------------------------------------
echo "-- Confirm and undo on real documents --\n";
$load = static function ($sql) use ($pdo) {
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    return $row ? array((int) $row['id'], json_decode($row['json_data'], true)) : array(0, null);
};
list($open_id, $open_doc) = $load("SELECT id, json_data FROM facilities_v2 WHERE status = 'Open' AND end_year IS NULL ORDER BY id LIMIT 1");
$report = array('stage' => 'closed', 'target_status' => 'Closed', 'closure_date' => '2025-06-01', 'publication_name' => 'Example News',
    'article_title' => 'Alpha Academy closes', 'publication_date' => '2025-06-02', 'article_url' => 'https://example.org/a');
$note = kop_closure_source_note($report);
$check('the note names the outlet, title, date and URL',
    $note === 'Closed (2025-06-01) per news report: Example News, "Alpha Academy closes", 2025-06-02 https://example.org/a', $note);

list($doc, $prev_status, $prev_end) = kop_closure_doc_apply($open_doc, 'Closed', 2025, $note);
$errors = array_filter(kop_facility_validate($doc), static function ($v) { return $v['severity'] === 'error'; });
$check("confirm on #$open_id: status Closed, end year 2025, note added",
    $doc['operatingPeriod']['status'] === 'Closed' && $doc['operatingPeriod']['endYear'] === 2025 && in_array($note, $doc['operatingPeriod']['notes'], true));
$check('the confirmed document passes the facility validator', !$errors, $errors ? json_encode(array_values($errors)[0]) : '');
$check('previous status and end year recorded', $prev_status === 'Open' && $prev_end === null);
list($again) = kop_closure_doc_apply($doc, 'Closed', 2025, $note);
$check('confirming twice does not repeat the note', count(array_keys($again['operatingPeriod']['notes'], $note, true)) === 1);

$applied = array_merge($report, array('previous_status' => $prev_status, 'previous_end_year' => $prev_end, 'applied_note' => $note));
$undone = kop_closure_doc_undo($doc, $applied);
$current = kop_closure_rewrite_period($open_doc, static function ($op) { return $op; });
// Schema 3 only adds these, empty; everything the record holds comes through.
$v3 = array('schema_version' => 1, 'targetedDiagnoses' => 1, 'targetedBehaviors' => 1, 'ttiPractices' => 1, 'survivorTestimony' => 1);
$check('an untouched rewrite changes nothing but the schema upgrade', kop_facility_same_document(
    array_diff_key($open_doc, $v3), array_diff_key($current, $v3)));
$check('undo gives back the original document', kop_facility_same_document($current, $undone));
$check('provenance survives the rewrite', $doc['provenance'] === $open_doc['provenance']);

$edited = $doc;
$edited['operatingPeriod']['status'] = 'Transferred';
$check('undo leaves a status someone changed since', kop_closure_doc_undo($edited, $applied)['operatingPeriod']['status'] === 'Transferred');

list($dated_id, $dated_doc) = $load("SELECT id, json_data FROM facilities_v2 WHERE status = 'Open' AND end_year IS NOT NULL ORDER BY id LIMIT 1");
if ($dated_doc) {
    $had = $dated_doc['operatingPeriod']['endYear'];
    list($doc2, , $prev_end2) = kop_closure_doc_apply($dated_doc, 'Closed', 2025, $note);
    $check("an end year entered by hand wins (#$dated_id keeps $had)", $doc2['operatingPeriod']['endYear'] === $had && $prev_end2 === (int) $had);
    $undone2 = kop_closure_doc_undo($doc2, array_merge($applied, array('previous_end_year' => $prev_end2)));
    $check('and undo keeps it', $undone2['operatingPeriod']['endYear'] === $had);
}

// ---------------------------------------------------------------------------
echo "-- Network map follows facilities_v2 --\n";
$graph = kop_network_map_graph();
$overrides = kop_network_map_status_overrides();
$expected = array();
foreach ($graph['nodes'] as $n) {
    $f = (int) ($n['facilityId'] ?? 0);
    $bucket = kop_network_map_status_bucket($n['status'] ?? '');
    if ($f > 0 && ($bucket === 'open' || $bucket === 'unknown')
        && $pdo->query('SELECT status FROM facilities_v2 WHERE id = ' . $f)->fetchColumn() === 'Closed') {
        $expected[$f] = 'closed';
    }
}
ksort($expected);
$got = $overrides;
ksort($got);
printf("  %d map names drawn open or blank are Closed in facilities_v2\n", count($overrides));
$check('overrides are exactly the open/blank names Closed in the data', $got === $expected);
$never = 0;
foreach ($graph['nodes'] as $n) {
    $f = (int) ($n['facilityId'] ?? 0);
    if ($f > 0 && isset($overrides[$f]) && in_array(kop_network_map_status_bucket($n['status'] ?? ''), array('closed', 'rebranded'), true)) $never++;
}
$check('a closed or rebranded name is never touched', $never === 0);
$check('cached read returns the same map', kop_network_map_status_overrides() === $overrides);
$check('config carries the overrides', (array) kop_network_map_config()['statusOverrides'] === $overrides);

if ($overrides) {
    $fid = (int) array_key_first($overrides);
    $node_id = null;
    foreach ($graph['nodes'] as $n) {
        if ((int) ($n['facilityId'] ?? 0) === $fid) { $node_id = $n['id']; break; }
    }
    $slice = kop_network_map_slice($node_id);
    $in = null;
    foreach ($slice['nodes'] ?? array() as $n) {
        if ($n['id'] === $node_id) $in = $n;
    }
    $check("the facility page map draws $node_id closed", $in && $in['status'] === 'closed', $in ? 'status ' . $in['status'] : 'not in slice');
}
$nodes = array(array('id' => 'a', 'facilityId' => 5, 'status' => 'open'), array('id' => 'b', 'status' => ''));
$check('apply writes only the listed facilities', kop_network_map_apply_status_overrides($nodes, array(5 => 'closed')) ===
    array(array('id' => 'a', 'facilityId' => 5, 'status' => 'closed'), array('id' => 'b', 'status' => '')));

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
