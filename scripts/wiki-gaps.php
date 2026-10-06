<?php
/**
 * Wiki entries against KOP's records (docs/PLAN.md 3.12, steps 1 and 2),
 * offline against tmp/prod.sqlite (scripts/sync-prod-sqlite.py). Read-only.
 *
 * For every current wiki entry (newest row per Reddit page): which record it
 * is linked to, or may be (inc/wiki-updates.php kop_wiki_upd_candidates()),
 * and for a linked or clearly matched program, what the record has that the
 * entry does not mention (kop_wiki_upd_gaps() over kop_facility_page_data()).
 *
 * Writes into --out (default tmp/wiki-updates/):
 *   links.json        every entry: link state and candidates
 *   gaps/<id>.json    per entry with a record: the entry, its record, its gaps
 *   report.md         counts, the entries with the most gaps, every conflict
 *
 * Usage (Local's bundled PHP):
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/wiki-gaps.php [--db=tmp/prod.sqlite] [--out=DIR] [--id=471,552] [--list]
 * --list prints each entry's link state and gap counts.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'out::', 'id::', 'list'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$out_dir = rtrim($args['out'] ?? (dirname(__DIR__) . '/tmp/wiki-updates'), '/\\');
$want = isset($args['id']) ? array_map('intval', explode(',', (string) $args['id'])) : array();
$list = isset($args['list']);
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
ini_set('memory_limit', '3G');

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/wiki-updates.php';

foreach (array($out_dir, $out_dir . '/gaps') as $d) if (!is_dir($d)) mkdir($d, 0777, true);
if (!$want) foreach (glob($out_dir . '/gaps/*.json') ?: array() as $f) unlink($f);

$entries = kop_wiki_upd_entries($pdo);
$states = array();
$links = array();
$by_kind = array();
$gap_kinds = array();
$per_entry = array();
$conflicts = array();
$no_record = array();
$t = microtime(true);

foreach ($entries as $id => $e) {
    if ($want && !in_array($id, $want, true)) continue;
    $cands = kop_wiki_upd_candidates($e, $pdo);
    $state = kop_wiki_upd_link_state($e, $cands);
    $states[$state] = ($states[$state] ?? 0) + 1;
    $by_kind[$e['kind']] = ($by_kind[$e['kind']] ?? 0) + 1;
    $fid = kop_wiki_upd_facility_id($e, $pdo);
    $how = $fid ? 'linked (' . ($e['facility_link_status'] ?: 'no status') . ')' : '';
    if (!$fid && $state === 'clear' && !empty($cands[0]['id'])) {
        $fid = (int) $cands[0]['id'];
        $how = 'clear match, not linked yet';
    }
    $links[$id] = array('id' => $id, 'program_name' => $e['program_name'], 'page' => $e['page'], 'kind' => $e['kind'],
        'state' => $state, 'linked_to' => (string) $e['facility_unique_name'], 'link_status' => (string) $e['facility_link_status'],
        'facility_id' => $fid, 'older_rows' => $e['older'], 'candidates' => array_slice($cands, 0, 6));

    if (!$fid || !in_array($e['kind'], array('program', 'other'), true)) {
        if ($e['kind'] === 'program' && $state !== 'linked') $no_record[] = $e;
        if ($list) printf("%5d  %-8s %-7s %s\n", $id, $state, $e['kind'], $e['program_name']);
        continue;
    }
    $page = kop_facility_page_data($fid);
    if (!$page) {
        if ($list) printf("%5d  %-8s record %d has no page data  %s\n", $id, $state, $fid, $e['program_name']);
        continue;
    }
    $gaps = kop_wiki_upd_gaps($e, $page, $pdo);
    $counts = array();
    foreach ($gaps as $g) {
        $counts[$g['kind']] = ($counts[$g['kind']] ?? 0) + 1;
        $gap_kinds[$g['kind']] = ($gap_kinds[$g['kind']] ?? 0) + 1;
        if ($g['conflict']) $conflicts[] = array('entry' => $e, 'record' => $page['name'], 'gap' => $g);
    }
    $per_entry[$id] = array('name' => $e['program_name'], 'record' => $page['name'], 'counts' => $counts, 'total' => count($gaps));
    list($w_start, $w_end) = kop_wiki_upd_years($e);
    $file = array(
        'entry' => array('id' => $id, 'program_name' => $e['program_name'], 'page' => $e['page'],
            'reddit_url' => $e['page'] !== '' ? 'https://www.reddit.com/r/troubledteens/wiki/' . $e['page'] . '/' : '',
            'years' => (string) $e['years_active'], 'place' => (string) $e['city_state'], 'updated_at' => (string) $e['updated_at'],
            'markdown_field' => trim((string) $e['original_markdown']) !== '' && kop_wiki_upd_markdown($e) === trim((string) $e['original_markdown']) ? 'original_markdown' : 'generated_markdown',
            'older_rows' => $e['older']),
        'record' => array('id' => $fid, 'name' => $page['name'], 'url' => kop_wiki_upd_live_url($page['url']), 'status' => $page['status'],
            'end_year' => $page['end_year'], 'operated' => $page['operated'], 'place' => $page['place'], 'link' => $how),
        'counts' => $counts,
        'gaps' => $gaps,
    );
    file_put_contents($out_dir . '/gaps/' . $id . '.json', json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ($list) printf("%5d  %-8s %-40s -> %-40s %s\n", $id, $state, mb_substr($e['program_name'], 0, 40), mb_substr($page['name'], 0, 40), json_encode($counts));
}

if (!$want) file_put_contents($out_dir . '/links.json', json_encode(array_values($links), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// ---- Report ----------------------------------------------------------------
ksort($states);
arsort($gap_kinds);
uasort($per_entry, function ($a, $b) { return $b['total'] <=> $a['total']; });
$with_gaps = count(array_filter($per_entry, function ($p) { return $p['total'] > 0; }));
$r = array();
$r[] = '# Wiki entries against KOP records';
$r[] = '';
$r[] = 'Built ' . gmdate('Y-m-d H:i') . ' UTC from `' . basename($db_path) . '` by `scripts/wiki-gaps.php` (docs/PLAN.md 3.12). Gaps are candidates: the drafting pass reads the entry and drops what it already says in other words.';
$r[] = '';
$r[] = '## Entries';
$r[] = '';
$r[] = '- Current entries (newest row per Reddit page): ' . count($links) . ' (' . implode(', ', array_map(function ($k, $v) { return "$v $k"; }, array_keys($by_kind), $by_kind)) . ')';
$r[] = '- Link state: ' . implode(', ', array_map(function ($k, $v) { return "$v $k"; }, array_keys($states), $states));
$r[] = '- Compared with a record: ' . count($per_entry) . '; with at least one gap: ' . $with_gaps;
$r[] = '';
$r[] = '## Gaps by kind';
$r[] = '';
$r[] = '| Kind | Gaps | Entries |';
$r[] = '|---|---|---|';
foreach ($gap_kinds as $k => $n) {
    $entries_n = count(array_filter($per_entry, function ($p) use ($k) { return !empty($p['counts'][$k]); }));
    $r[] = "| $k | $n | $entries_n |";
}
$r[] = '';
$r[] = '## Most to add (top 40)';
$r[] = '';
$r[] = '| Entry | Record | Gaps |';
$r[] = '|---|---|---|';
foreach (array_slice($per_entry, 0, 40, true) as $id => $p) {
    $r[] = '| ' . $id . ' ' . str_replace('|', '/', $p['name']) . ' | ' . str_replace('|', '/', $p['record']) . ' | '
        . implode(', ', array_map(function ($k, $v) { return "$v $k"; }, array_keys($p['counts']), $p['counts'])) . ' |';
}
$r[] = '';
$r[] = '## Conflicts (' . count($conflicts) . '): the entry and the record disagree; never written over';
$r[] = '';
foreach ($conflicts as $c) $r[] = '- ' . $c['entry']['id'] . ' ' . $c['entry']['program_name'] . ' (' . $c['entry']['years_active'] . '): ' . $c['gap']['text'];
$r[] = '';
$r[] = '## Program entries with no link and no clear match (' . count($no_record) . ')';
$r[] = '';
foreach ($no_record as $e) {
    $c = $links[$e['id']]['candidates'];
    $r[] = '- ' . $e['id'] . ' ' . $e['program_name'] . ' (' . $e['city_state'] . '): ' . ($c
        ? count($c) . ' candidates, e.g. ' . implode('; ', array_map(function ($x) { return $x['name'] . ' (' . $x['place'] . ', ' . $x['reason'] . ')'; }, array_slice($c, 0, 3)))
        : 'no candidate');
}
file_put_contents($out_dir . '/report.md', implode("\n", $r) . "\n");

printf("\n%d entries (%s)\nlinks: %s\ncompared: %d, with gaps: %d, conflicts: %d\ngaps: %s\n%.1fs, report in %s/report.md\n",
    count($links), json_encode($by_kind), json_encode($states), count($per_entry), $with_gaps, count($conflicts), json_encode($gap_kinds),
    microtime(true) - $t, $out_dir);
