<?php
/**
 * inc/wiki-updates.php (docs/PLAN.md 3.12): the text helpers, how wiki
 * entries are matched to facility records, and the gaps found for real
 * entries, against tmp/prod.sqlite (read-only). The Wiki links review queue
 * (link, set aside, undo) is checked by scripts/test-review-inbox.php
 * --source=wiki-links.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-gaps.php [--db=tmp/prod.sqlite]
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
ini_set('memory_limit', '3G');
require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/wiki-updates.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

echo "-- Text helpers --\n";
$check('url keys ignore scheme, www, tracking, fragment, slash and Reddit backslashes',
    kop_wiki_upd_url_key('https://www.example.com/a\_b/?utm_source=x#top') === kop_wiki_upd_url_key('http://example.com/a_b'));
$w = kop_wiki_upd_words("**Alec Lansing**, 17, died in 2014. The [Salt Lake Tribune](https://sltrib.com/x) reported it.");
$check('a name is found in the words, in order', kop_wiki_upd_mentions($w, 'Salt Lake Tribune') && !kop_wiki_upd_mentions($w, 'Tribune Salt'));
$check('a person is found without middle names', kop_wiki_upd_mentions_person($w, 'Alec Sanford Lansing') && !kop_wiki_upd_mentions_person($w, 'Alec Smith'));
$years = array(
    '2002-present' => array(2002, 'present'), '?-2017' => array(null, 2017), '1976-1993' => array(1976, 1993), '' => array(null, null),
);
foreach ($years as $in => $want) {
    $got = kop_wiki_upd_years(array('years_active' => $in, 'original_markdown' => '', 'generated_markdown' => '', 'submitted_by' => 'bulk-upload'));
    $check("years '$in'", $got === $want, json_encode($got));
}
$check('states from a two-place entry', kop_wiki_upd_states(array('city_state' => 'Sandpoint, ID/Heron, MT')) === array('ID', 'MT'));
$check('states from a full state name', kop_wiki_upd_states(array('city_state' => 'Lucedale, Mississippi')) === array('MS'));
$check('town words are not distinctive words of a name', !array_diff(kop_wiki_upd_tokens('Woodland Hills Academy'), kop_wiki_upd_tokens('Woodland Hills, UT')));

echo "\n-- Entries --\n";
$entries = kop_wiki_upd_entries($pdo);
$pages = array();
foreach ($entries as $e) if ($e['page'] !== '') $pages[$e['page']] = ($pages[$e['page']] ?? 0) + 1;
$check('one current entry per Reddit page', max($pages) === 1, count($entries) . ' entries, ' . count($pages) . ' pages');
$kinds = array_count_values(array_column($entries, 'kind'));
$check('state lists are lists, not programs', ($kinds['list'] ?? 0) >= 50, json_encode($kinds));
$by_name = array();
foreach ($entries as $e) $by_name[mb_strtolower(trim($e['program_name']))][] = $e;
$entry = function ($name) use ($by_name) { return $by_name[mb_strtolower($name)][0] ?? null; };

echo "\n-- Matching --\n";
$cands_of = function ($name) use ($entry, $pdo) { $e = $entry($name); return $e ? kop_wiki_upd_candidates($e, $pdo) : null; };
$c = $cands_of('Telos RTC');
$check('a differently worded name in the same town (Telos RTC -> Telos Academy)', $c && $c[0]['name'] === 'Telos Academy', $c ? $c[0]['reason'] : 'no entry');
$c = $cands_of('Woodland Hills Academy');
$check('a town\'s name alone matches nothing (Woodland Hills Academy)', is_array($c) && !in_array('Woodland Hills Maternity Home', array_column($c, 'name'), true), json_encode(array_column((array) $c, 'name')));
$c = $cands_of('Clearview Horizon');
$check('a two-state entry offers the record in each state', is_array($c) && count(array_unique(array_column(array_slice($c, 0, 2), 'place'))) === 2, json_encode(array_column((array) $c, 'place')));
$e = $entry('Mountain Home Academy');
$c = $e ? kop_wiki_upd_candidates($e, $pdo) : array();
$check('a page with no years or place matches only its exact name', $e && $e['kind'] === 'other' && count($c) === 1 && kop_wiki_upd_link_state($e, $c) === 'clear');
$c = $cands_of('Acadia Healthcare');
$check('a company page matches the company', $c && !empty($c[0]['operator']), json_encode($c[0] ?? null));
$lists = array_filter($entries, function ($e) { return $e['kind'] === 'list'; });
$check('a list page is never linked', !array_filter($lists, function ($e) { return kop_wiki_upd_link_state($e, array()) !== 'skip'; }));

echo "\n-- Gaps --\n";
$gaps_for = function ($name) use ($entry, $pdo) {
    $e = $entry($name);
    if (!$e) return null;
    $fid = kop_wiki_upd_facility_id($e, $pdo);
    if (!$fid) {
        $c = kop_wiki_upd_candidates($e, $pdo);
        $fid = $c && kop_wiki_upd_link_state($e, $c) === 'clear' ? (int) $c[0]['id'] : 0;
    }
    return $fid ? kop_wiki_upd_gaps($e, kop_facility_page_data($fid), $pdo) : null;
};
$kinds_of = function ($gaps) { return array_count_values(array_column((array) $gaps, 'kind')); };

$g = $gaps_for('Trails Carolina');
$closure = array_values(array_filter((array) $g, function ($x) { return $x['kind'] === 'closure'; }));
$check('a confirmed closure the entry lacks (Trails Carolina, 2024), cited to the article', $closure && strpos($closure[0]['text'], '2024') !== false
    && strpos($closure[0]['source_url'], 'kidsoverprofits.org') === false && !$closure[0]['conflict'], $closure[0]['source_url'] ?? '');

$g = $gaps_for('Integrity House RTC');
$k = $kinds_of($g);
$late = array_filter((array) $g, function ($x) { return preg_match('/(\d{4})/', $x['date'], $m) && (int) $m[1] > 2014; });
$check('an entry about an earlier name gets no status and nothing dated after its years (Integrity House -> Havenwood)', is_array($g) && empty($k['closure']) && empty($k['operator']) && !$late, json_encode($k));

$g = $gaps_for('Carlbrook School');
$check('a linked entry is compared with its record (Carlbrook School)', is_array($g));

$all = 0;
$no_source = array();
$bad_kind = array();
foreach ($entries as $e) {
    $fid = kop_wiki_upd_facility_id($e, $pdo);
    if (!$fid || !in_array($e['kind'], array('program', 'other'), true)) continue;
    $page = kop_facility_page_data($fid);
    if (!$page) continue;
    foreach (kop_wiki_upd_gaps($e, $page, $pdo) as $x) {
        $all++;
        if (trim($x['source_url']) === '') $no_source[] = $e['id'] . ':' . $x['kind'];
        if (!in_array($x['kind'], array('closure', 'name', 'operator', 'news', 'lawsuit', 'death', 'finding', 'incident', 'staff', 'staff_other'), true)) $bad_kind[] = $x['kind'];
        if (preg_match('#example\.test#', $x['source_url'])) $no_source[] = $e['id'] . ':' . $x['kind'] . ' (test host)';
    }
}
$check('every gap of the linked entries cites a live address', !$no_source, $all . ' gaps; ' . implode(', ', array_slice($no_source, 0, 6)));
$check('no survivor posts or testimony among the gaps', !$bad_kind, implode(',', array_unique($bad_kind)));

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
