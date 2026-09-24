<?php
/**
 * Offline check of the generated facility pages (inc/facility-pages.php)
 * against a SQLite mirror of production (scripts/sync-prod-sqlite.py writes
 * tmp/prod.sqlite). WordPress is stubbed and $wpdb runs the module's real
 * SQL through PDO/SQLite, so the index build, the eligibility rule, the
 * slugs, the routing decisions and the template all run as they would on
 * the site. Read-only: nothing is written to the mirror.
 *
 * Usage (Local's bundled PHP; there is no php on the PATH):
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-facility-pages.php [--db=tmp/prod.sqlite] [--render=8] [--id=14182,10371] [--out=<dir>]
 *
 * Prints the index size, how many records qualified and why, slug checks,
 * the routing outcomes for the interesting cases, and renders sample pages
 * to HTML files for a look in a browser.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'render::', 'id::', 'out::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$render_n = isset($args['render']) ? (int) $args['render'] : 8;
$want_ids = isset($args['id']) ? array_map('intval', explode(',', (string) $args['id'])) : array();
$out_dir = $args['out'] ?? (sys_get_temp_dir() . '/kop-facility-pages');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);

require __DIR__ . '/kop-test-harness.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// ---------------------------------------------------------------------------
// Index
// ---------------------------------------------------------------------------

echo "-- Index --\n";
$t = microtime(true);
$index = kop_facility_pages_index(true);
$build_s = microtime(true) - $t;
$total = (int) $wpdb->get_var('SELECT COUNT(*) FROM facilities_v2');
$eligible = count($index['ids']);
$editorial = 0;
foreach ($index['ids'] as $e) if ($e['editorial'] !== '') $editorial++;
printf("  facilities_v2 rows: %d\n  with a page: %d (%.1f%%), of which editorial: %d\n  build time: %.2fs, queries: %d, serialized: %d KB\n",
    $total, $eligible, $eligible * 100 / max(1, $total), $editorial, $build_s, $wpdb->queries, (int) (strlen(serialize($index)) / 1024));
$check('index has entries', $eligible > 0);
$check('fingerprint set', $index['fingerprint'] !== '');
$check('cached read returns the same index', kop_facility_pages_index() === $index);

// Why records qualified (recomputed with the same link sets the build used).
$links = kop_facility_pages_link_sets();
$hist = array();
$only = array();
$thin_status = array();
$signal_counts = array();
foreach ($wpdb->get_results('SELECT id, unique_name, json_data FROM facilities_v2', ARRAY_A) as $row) {
    $doc = kop_v2_decode($row['json_data']);
    if (!$doc) continue;
    $s = kop_facility_page_signals($doc, (int) $row['id'], $links, $row['unique_name']);
    foreach ($s as $sig) $hist[$sig] = ($hist[$sig] ?? 0) + 1;
    if (count($s) === 1) $only[$s[0]] = ($only[$s[0]] ?? 0) + 1;
    $signal_counts[min(count($s), 8)] = ($signal_counts[min(count($s), 8)] ?? 0) + 1;
    if (!$s) $thin_status[$doc['operatingPeriod']['status'] ?? '?'] = ($thin_status[$doc['operatingPeriod']['status'] ?? '?'] ?? 0) + 1;
}
arsort($hist);
echo "  signals (records carrying each):\n";
foreach ($hist as $sig => $n) printf("    %5d  %s%s\n", $n, $sig, isset($only[$sig]) ? "  ({$only[$sig]} qualify on this alone)" : '');
ksort($signal_counts);
echo '  signals per record: ' . implode(', ', array_map(function ($k, $v) { return ($k === 8 ? '8+' : $k) . ':' . $v; }, array_keys($signal_counts), $signal_counts)) . "\n";
echo '  records without a page, by status: ' . json_encode($thin_status) . "\n";

// ---------------------------------------------------------------------------
// Slugs
// ---------------------------------------------------------------------------

echo "\n-- Slugs --\n";
$bad = array();
$suffixed = array();
foreach ($index['ids'] as $id => $e) {
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $e['slug'])) $bad[] = $id . ':' . $e['slug'];
    if (preg_match('/-' . $id . '$/', $e['slug'])) $suffixed[] = $e['slug'];
}
$check('every slug is lowercase a-z, 0-9 and single dashes', !$bad, implode(' | ', array_slice($bad, 0, 5)));
$check('slug map and id map agree', count($index['slugs']) === count($index['ids']));
$ok = true;
foreach ($index['slugs'] as $slug => $id) if (($index['ids'][$id]['slug'] ?? null) !== $slug) { $ok = false; break; }
$check('every slug resolves back to its id', $ok);
echo '  id-suffixed slugs (same name, place and city): ' . count($suffixed) . ($suffixed ? ' e.g. ' . implode(', ', array_slice($suffixed, 0, 4)) : '') . "\n";
$samples = array_slice($index['ids'], 0, 5, true);
foreach ($samples as $id => $e) echo "  $id  /facility/{$e['slug']}/  {$e['name']}" . ($e['editorial'] ? "  -> {$e['editorial']}" : '') . "\n";
$hyde = array();
foreach ($index['ids'] as $id => $e) if (stripos($e['name'], 'Hyde School') === 0 || stripos($e['name'], 'Beloved Ones') === 0) $hyde[] = "$id {$e['slug']}";
echo '  same-name examples: ' . implode(' | ', $hyde) . "\n";

// Name lookup used by the story-arc "Learn more about X" buttons
// (kop_news_arc_facility_link in inc/features.php).
echo "\n-- Name lookup --\n";
foreach (array('Hyde School', 'Provo Canyon School', 'hyde school', 'Provo Canyon School, Inc.') as $probe) {
    $url = kop_facility_page_url_for_name($probe);
    echo "  $probe => " . ($url === '' ? '(none)' : $url) . "\n";
    $check("name lookup resolves '$probe'", $url !== '' && strpos($url, 'search=') === false, $url);
}
$check('name lookup ignores unknown names', kop_facility_page_url_for_name('No Such Place Academy 9000') === '');

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

echo "\n-- Routing --\n";
$route = function ($slug) {
    $GLOBALS['kop_test_query_vars'] = array('kop_facility' => $slug);
    $GLOBALS['kop_facility_page'] = null;
    $GLOBALS['kop_test_status'] = 0;
    $GLOBALS['wp_query'] = new WP_Query();
    try {
        kop_facility_pages_route();
    } catch (KOP_Test_Redirect $r) {
        return array('redirect' => $r->status, 'to' => $r->url);
    }
    if ($GLOBALS['wp_query']->is_404) return array('404' => true);
    return array('page' => $GLOBALS['kop_facility_page']['name'] ?? '', 'status' => $GLOBALS['kop_test_status']);
};

$first_generated = null;
$first_editorial = null;
foreach ($index['ids'] as $id => $e) {
    if ($e['editorial'] === '' && $first_generated === null) $first_generated = $id;
    if ($e['editorial'] !== '' && $first_editorial === null) $first_editorial = $id;
    if ($first_generated !== null && $first_editorial !== null) break;
}
$thin_id = (int) $wpdb->get_var('SELECT MIN(id) FROM facilities_v2 WHERE id NOT IN (' . implode(',', array_map('intval', array_keys($index['ids']))) . ')');

$r = $route($index['ids'][$first_generated]['slug']);
$check('canonical slug renders the page', isset($r['page']) && $r['page'] === $index['ids'][$first_generated]['name'] && $r['status'] === 200, json_encode($r));
$r = $route((string) $first_generated);
$check('numeric id 301s to the slug', isset($r['redirect']) && $r['redirect'] === 301 && $r['to'] === kop_facility_pages_url_for_slug($index['ids'][$first_generated]['slug']), json_encode($r));
$r = $route(strtoupper($index['ids'][$first_generated]['slug']));
$check('uppercase slug 301s to the canonical one', isset($r['redirect']) && $r['redirect'] === 301, json_encode($r));
$r = $route('stale-name-' . $first_generated);
$check('stale slug ending in the id 301s to the current slug', isset($r['redirect']) && $r['redirect'] === 301, json_encode($r));
if ($first_editorial !== null) {
    $r = $route($index['ids'][$first_editorial]['slug']);
    $check('facility with an editorial profile 301s to the post', isset($r['redirect']) && $r['redirect'] === 301 && $r['to'] === $index['ids'][$first_editorial]['editorial'], json_encode($r));
}
$r = $route((string) $thin_id);
$check('thin record 302s to a search', isset($r['redirect']) && $r['redirect'] === 302 && strpos($r['to'], 'search=') !== false, json_encode($r));
$r = $route('no-such-facility-xx');
$check('unknown slug is a 404', isset($r['404']), json_encode($r));
$r = $route('999999999');
$check('unknown id is a 404', isset($r['404']), json_encode($r));
$check('kop_facility_page_url for a generated page', kop_facility_page_url($first_generated) === kop_facility_pages_url_for_slug($index['ids'][$first_generated]['slug']));
$check('kop_facility_page_url for a thin record is empty', kop_facility_page_url($thin_id) === '');

// ---------------------------------------------------------------------------
// The network map's facility id => profile URL map (inc/network-map.php)
// ---------------------------------------------------------------------------
// The map is keyed by facility id and cached for a day. The first request
// after a cache miss returned it keyed correctly and every request after
// that got it back renumbered 0, 1, 2 (array_merge), so the drawer never
// linked a profile on the live page. Both the fresh and the cached copy
// have to carry the ids.
if (function_exists('kop_network_map_facility_urls') && function_exists('kop_network_map_graph') && kop_network_map_graph()) {
    $fresh = kop_network_map_facility_urls();
    $again = kop_network_map_facility_urls();
    $graph_ids = array();
    foreach (kop_network_map_graph()['nodes'] as $n) {
        if (!empty($n['facilityId'])) $graph_ids[(int) $n['facilityId']] = true;
    }
    $keyed = function ($urls) use ($graph_ids) {
        if (!is_array($urls) || !$urls) return false;
        foreach ($urls as $id => $url) {
            if (!isset($graph_ids[$id]) || !is_string($url) || $url === '') return false;
        }
        return true;
    };
    $check('network map facility URLs are keyed by facility id', $keyed($fresh), json_encode(array_slice(array_keys($fresh), 0, 5)));
    $check('network map facility URLs keep their ids through the cache', $keyed($again) && $again === $fresh, json_encode(array_slice(array_keys($again), 0, 5)));
    $check('network map facility URLs cover linked nodes', count($fresh) > 100, 'only ' . count($fresh) . ' of ' . count($graph_ids));

    // Every record the map draws with a connection has a page, and the page
    // lists what the map says about it.
    $drawn = kop_network_map_facility_connections();
    $existing = array();
    foreach ($wpdb->get_col('SELECT id FROM facilities_v2') as $fid) $existing[(int) $fid] = true;
    $pageless = array();
    foreach ($drawn as $fid => $entry) {
        if (!empty($entry['links']) && isset($existing[$fid]) && kop_facility_page_url($fid) === '') $pageless[] = $fid . ' ' . $entry['name'];
    }
    $check('every facility on the network map has a page', !$pageless, count($pageless) . ': ' . implode('; ', array_slice($pageless, 0, 5)));
    $sample = null;
    foreach ($drawn as $fid => $entry) {
        if (isset($existing[$fid]) && count($entry['links']) >= 3) { $sample = $fid; break; }
    }
    if ($sample !== null) {
        $net = kop_facility_pages_network($sample);
        $listed = 0;
        foreach ($net['groups'] ?? array() as $g) $listed += count($g['items']);
        $check('a facility page lists its network connections', $listed > 0 && strpos($net['map_url'], '#open=' . rawurlencode($drawn[$sample]['node'])) !== false,
            $drawn[$sample]['name'] . ': ' . $listed . ' listed, ' . ($net['map_url'] ?? 'no map link'));
        $want_ids[] = $sample;
    }
} else {
    $check('network map module loads with a graph', false, 'inc/network-map.php or js/data/network/graph.json missing');
}

// ---------------------------------------------------------------------------
// Render samples
// ---------------------------------------------------------------------------

echo "\n-- Render --\n";
$picks = $want_ids;
if (!$picks) {
    // The richest records, plus one qualifying on each linked table alone.
    $by_signals = $index['ids'];
    uasort($by_signals, function ($a, $b) { return $b['signals'] <=> $a['signals']; });
    foreach (array_keys($by_signals) as $id) {
        if ($by_signals[$id]['editorial'] !== '') continue;
        $picks[] = $id;
        if (count($picks) >= max(1, $render_n - 4)) break;
    }
    foreach ($wpdb->get_results('SELECT id, unique_name, json_data FROM facilities_v2', ARRAY_A) as $row) {
        $doc = kop_v2_decode($row['json_data']);
        if (!$doc) continue;
        $s = kop_facility_page_signals($doc, (int) $row['id'], $links, $row['unique_name']);
        foreach (array('inspections', 'memorials', 'lawsuits', 'news') as $want) {
            if ($s === array($want) && !isset($picked_only[$want]) && ($index['ids'][(int) $row['id']]['editorial'] ?? '') === '') {
                $picked_only[$want] = true;
                $picks[] = (int) $row['id'];
            }
        }
    }
}
$picks = array_values(array_unique($picks));
$sections_seen = array();
foreach ($picks as $id) {
    $t = microtime(true);
    $data = kop_facility_page_data($id);
    $data_s = microtime(true) - $t;
    if (!$data) { $check("data for $id", false, 'no row'); continue; }
    $GLOBALS['kop_facility_page'] = $data;
    ob_start();
    $rendered = true;
    try {
        include dirname(__DIR__) . '/templates/facility-page.php';
    } catch (Throwable $e) {
        $rendered = false;
        ob_end_clean();
        $check("render $id {$data['name']}", false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        continue;
    }
    $html = ob_get_clean();
    $file = $out_dir . '/' . $data['slug'] . '.html';
    file_put_contents($file, $html);
    preg_match_all('/<section class="kop-fp-section[^"]*" id="([a-z]+)"/', $html, $m);
    foreach ($m[1] as $sec) $sections_seen[$sec] = ($sections_seen[$sec] ?? 0) + 1;
    $check(sprintf('render %d %s', $id, $data['name']), $rendered && strpos($html, '<h1') !== false,
        sprintf('%.0f ms, %d KB, sections: %s', $data_s * 1000, strlen($html) / 1024, implode(',', $m[1]) ?: 'none'));
    echo '      ' . $data['summary'] . "\n";
    echo '      ' . $file . "\n";
}
echo '  sections rendered across samples: ' . json_encode($sections_seen) . "\n";

// Where-to-report callout. It appears only for a state the reporting directory
// covers, and it has to deep-link into that state rather than the bare page.
// Without this, a break in the callout would go unnoticed until it shipped.
$reporting_seen = 0;
$reporting_bad = array();
foreach ($picks as $rid) {
    $rdata = kop_facility_page_data($rid);
    if (!$rdata) continue;
    $rfile = $out_dir . '/' . $rdata['slug'] . '.html';
    if (!is_readable($rfile)) continue;
    $rhtml = file_get_contents($rfile);
    $has = strpos($rhtml, 'kop-fp-reporting') !== false;
    $covered = kop_reporting_state($rdata['state_name'] !== '' ? $rdata['state_name'] : $rdata['state_code']) !== null;
    if ($has !== $covered) {
        $reporting_bad[] = $rdata['slug'] . ($covered ? ' (covered, no callout)' : ' (uncovered, callout shown)');
    } elseif ($has && strpos($rhtml, 'state=') === false) {
        $reporting_bad[] = $rdata['slug'] . ' (callout does not deep-link a state)';
    }
    if ($has) $reporting_seen++;
}
$check('where-to-report callout matches directory coverage', !$reporting_bad,
    $reporting_bad ? implode('; ', $reporting_bad)
                   : $reporting_seen . ' of ' . count($picks) . ' sampled facilities carry it');


// Sitemap entries
$entries = kop_facility_pages_sitemap_entries();
$operator_pages = function_exists('kop_operator_pages_index') ? count(kop_operator_pages_index()['ids']) : 0;
$check('sitemap lists every generated page', count($entries) === $eligible - $editorial + $operator_pages, count($entries) . ' entries, ' . $operator_pages . ' of them parent companies');

echo "\n" . ($failures ? "$failures FAILED" : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
