<?php
/**
 * Offline check of the name sections on renamed programs' facility pages
 * (inc/facility-eras.php) against the SQLite mirror of production
 * (tmp/prod.sqlite). The saved rename years are read from the mirror's copy
 * of the kop_network_rename_review option, so the pages are cut as the live
 * site cuts them. Read-only.
 *
 * Usage (Local's bundled PHP):
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-facility-eras.php [--db=tmp/prod.sqlite] [--list] [--out=<dir>]
 *
 * --list prints every program whose page is cut, with each name's years and
 * what stands under it, and every rename line that leaves its page whole.
 * The cut pages are rendered to --out (default: the temp dir) for a look.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list', 'out::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$list = isset($args['list']);
$out_dir = $args['out'] ?? (sys_get_temp_dir() . '/kop-facility-eras');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/citations.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// ---------------------------------------------------------------------------
// The rules, on a made-up line of names
// ---------------------------------------------------------------------------

echo "-- Rules --\n";
$chain = array(
    array('name' => 'Copper Canyon Academy', 'cut' => null),
    array('name' => 'Sedona Sky Academy', 'cut' => 2014),
    array('name' => 'Third Name', 'cut' => 2020),
);
$check('an item before the rename stands under the earlier name', kop_facility_eras_place($chain, 2009, '', -1) === 0);
$check('an item after it stands under the later name', kop_facility_eras_place($chain, 2016, '', -1) === 1);
$check('an item in the rename year stands under the later name', kop_facility_eras_place($chain, 2014, 'a girl ran away', -1) === 1);
$check('...unless its words give only the earlier name', kop_facility_eras_place($chain, 2014, 'copper canyon academy sold', -1) === 0);
$check('...and not when they give both', kop_facility_eras_place($chain, 2014, 'copper canyon academy is now sedona sky academy', -1) === 1);
$check('the last name takes everything from its year on', kop_facility_eras_place($chain, 2024, '', -1) === 2);
$check('an undated item stands under its record\'s one name', kop_facility_eras_place($chain, 0, '', 1) === 1);
$check('an undated item with no such name stands under none', kop_facility_eras_place($chain, 0, '', -1) === -1);
$check('years read "1998 to 2014", "from 2014", "until 2014"',
    kop_facility_eras_years_label(1998, 2014) === '1998 to 2014' && kop_facility_eras_years_label(2014, null) === 'from 2014' && kop_facility_eras_years_label(null, 2014) === 'until 2014');
$check('item years: news date, lawsuit year, undated incident',
    kop_facility_eras_item_year('news', array('date' => '2016-03-01')) === 2016
    && kop_facility_eras_item_year('lawsuits', array('year' => '2011')) === 2011
    && kop_facility_eras_item_year('incidents', array('year' => 9999)) === 0
    && kop_facility_eras_item_year('news', array('date' => '0000-00-00')) === 0);

// ---------------------------------------------------------------------------
// The live site's saved renames
// ---------------------------------------------------------------------------

echo "\n-- Saved renames (from the mirror) --\n";
$raw = $wpdb->get_var("SELECT option_value FROM wpdl_options WHERE option_name = 'kop_network_rename_review'");
$decisions = $raw ? @unserialize($raw) : array();
if (!is_array($decisions)) $decisions = array();
$GLOBALS['kop_test_options']['kop_network_rename_review'] = $decisions;
$saved = count(array_filter($decisions, function ($d) { return ($d['decision'] ?? '') === 'saved'; }));
echo '  ' . count($decisions) . " decisions, $saved saved\n";
$check('the mirror holds saved rename years', $saved > 0);

$map = kop_facility_eras_map();
$check('the map\'s rename lines were read', count($map['nodes']) > 20, count($map['nodes']) . ' names on a rename line');

// ---------------------------------------------------------------------------
// Every record on a rename line
// ---------------------------------------------------------------------------

echo "\n-- Pages --\n";
$kinds = kop_facility_eras_kinds();
$count = function ($kind, $items) {
    return $kind === 'staff' ? count($items['administrator'] ?? array()) + count($items['notableStaff'] ?? array()) : count($items);
};
$cut = array();
$whole = array();
$bad = array();
$ids = array_keys($map['facility']);
sort($ids);
foreach ($ids as $fid) {
    $chain = kop_facility_eras_chain($fid);
    $name = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM facilities_v2 WHERE id = %d', $fid));
    if ($name === '') continue;
    if (count($chain) < 2) {
        $whole[] = "$fid $name";
        continue;
    }
    // The names are in order and every cut is a year.
    $last = 0;
    foreach ($chain as $i => $era) {
        if ($i === 0) continue;
        if (!$era['cut'] || $era['cut'] < $last) $bad[] = "$fid $name: cut " . json_encode($era['cut']);
        $last = (int) $era['cut'];
    }
    $data = kop_facility_page_data($fid);
    if (!$data) continue;
    if (empty($data['eras'])) {
        $whole[] = "$fid $name (nothing dated)";
        continue;
    }
    $eras = $data['eras'];
    // One record holding every name: nothing is lost and nothing is added.
    $records = array();
    foreach ($chain as $era) if ($era['facility_id'] > 0) $records[$era['facility_id']] = true;
    if (count($records) === 1) {
        $v = (array) ($data['inspections']['violations'] ?? array());
        if (!empty($data['program_homes']['violations'])) $v = array_merge($v, $data['program_homes']['violations']);
        $own = array('memorials' => $data['memorials'], 'violations' => $v, 'lawsuits' => $data['lawsuits'],
            'incidents' => $data['incidents'], 'news' => $data['news'], 'staff' => $data['staff']);
        foreach ($kinds as $kind) {
            if ($eras['totals'][$kind] !== $count($kind, $own[$kind])) {
                $bad[] = "$fid $name: $kind " . $count($kind, $own[$kind]) . ' on the record, ' . $eras['totals'][$kind] . ' on the page';
            }
        }
    }
    // No item stands under two names.
    foreach (array('memorials', 'violations', 'lawsuits', 'news') as $kind) {
        $seen = array();
        foreach (array_merge(array($eras['rest']), $eras['list']) as $part) {
            foreach ($part[$kind] as $item) {
                if (isset($seen[$item['id']])) $bad[] = "$fid $name: $kind {$item['id']} twice";
                $seen[$item['id']] = true;
            }
        }
    }
    // Every dated item stands inside its name's years.
    foreach ($eras['list'] as $i => $entry) {
        foreach (array('memorials', 'violations', 'lawsuits', 'incidents', 'news') as $kind) {
            foreach ($entry[$kind] as $item) {
                $y = kop_facility_eras_item_year($kind, $item);
                if (!$y) continue;
                $from = (int) ($chain[$i]['cut'] ?? 0);
                $to = isset($chain[$i + 1]) ? (int) $chain[$i + 1]['cut'] : 9999;
                if (($i > 0 && $y < $from) || $y > $to) $bad[] = "$fid $name: $kind dated $y under {$entry['name']}";
            }
        }
    }

    // The page renders, with one section per name ahead of the ordinary ones.
    $GLOBALS['kop_facility_page'] = $data;
    ob_start();
    try {
        include dirname(__DIR__) . '/templates/facility-page.php';
        $html = ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        $bad[] = "$fid $name: render " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        continue;
    }
    file_put_contents($out_dir . '/' . $data['slug'] . '.html', $html);
    foreach ($eras['list'] as $entry) {
        if (strpos($html, 'id="' . $entry['id'] . '"') === false) $bad[] = "$fid $name: no section {$entry['id']}";
        if (strpos($html, '<span class="kop-fp-era-as">As</span> ' . esc_html($entry['name'])) === false) $bad[] = "$fid $name: no heading for {$entry['name']}";
        if (strpos($html, 'href="#' . $entry['id'] . '"') === false) $bad[] = "$fid $name: no jump link to {$entry['id']}";
    }
    preg_match_all('/href="#([^"]+)"/', $html, $m);
    foreach (array_unique($m[1]) as $anchor) {
        if (strpos($html, 'id="' . $anchor . '"') === false) $bad[] = "$fid $name: link to #$anchor, which is not on the page";
    }
    if (strpos($html, 'kop-fp-footer') === false) $bad[] = "$fid $name: page did not render to the end";

    $lines = array();
    foreach ($eras['list'] as $entry) {
        $bits = array();
        foreach ($kinds as $kind) {
            $n = $count($kind, $entry[$kind]);
            if ($n) $bits[] = "$n $kind";
        }
        $lines[] = '      As ' . $entry['name'] . ' (' . ($entry['years'] ?: 'years unknown') . ')' . ($entry['url'] !== '' ? ' [own record]' : '') . ': ' . ($bits ? implode(', ', $bits) : 'nothing dated');
    }
    $bits = array();
    foreach ($kinds as $kind) {
        $n = $count($kind, $eras['rest'][$kind]);
        if ($n) $bits[] = "$n $kind";
    }
    $lines[] = '      Left in the ordinary sections: ' . ($bits ? implode(', ', $bits) : 'nothing');
    $cut[] = "  $fid $name  /facility/{$data['slug']}/\n" . implode("\n", $lines);
}
$check('records on a rename line were found', count($ids) > 30, count($ids) . ' records');
$check('some pages are cut into name sections', count($cut) > 0, count($cut) . ' cut, ' . count($whole) . ' left whole');
$check('every cut page is sound', !$bad, implode("\n      ", array_slice($bad, 0, 12)));

// A page nobody renamed is as it was.
$plain = (int) $wpdb->get_var('SELECT id FROM facilities_v2 WHERE id NOT IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id LIMIT 1');
$data = kop_facility_page_data($plain);
$check('a program with no rename keeps its page whole', $data && $data['eras'] === null);

// A skipped line and a line with the same years on both names leave the page whole.
echo "\n" . count($cut) . ' pages cut, ' . count($whole) . " left whole. Rendered to $out_dir\n";
if ($list) {
    echo "\n-- Cut --\n" . implode("\n", $cut) . "\n\n-- Left whole (no rename year, or a split or join) --\n  " . implode("\n  ", $whole) . "\n";
}

echo "\n" . ($failures ? "$failures FAILED" : 'All passed') . "\n";
exit($failures ? 1 : 0);
