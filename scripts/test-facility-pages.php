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
require_once dirname(__DIR__) . '/inc/citations.php';

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

    // Every facility the map draws gets a slice for its page's map: the
    // root in it, every name and both ends of every line in graph.json,
    // small enough to print inline, and the cap note only on a capped root.
    // scripts/test-network-embed.js holds each one to what the map shows.
    $graph = kop_network_map_graph();
    $graph_nodes = array();
    foreach ($graph['nodes'] as $n) $graph_nodes[(string) $n['id']] = true;
    $direct = array();
    foreach ($graph['edges'] as $e) {
        $direct[$e['source']] = ($direct[$e['source']] ?? 0) + 1;
        $direct[$e['target']] = ($direct[$e['target']] ?? 0) + 1;
    }
    $bad = array();
    $largest = array(0, '');
    foreach ($drawn as $fid => $entry) {
        $slice = kop_network_map_slice($entry['node']);
        $ids = $slice ? array_flip(array_column($slice['nodes'], 'id')) : array();
        $bytes = $slice ? strlen(json_encode($slice)) : 0;
        if ($bytes > $largest[0]) $largest = array($bytes, $entry['name']);
        $ends = true;
        foreach ($slice['edges'] ?? array() as $e) {
            if (!isset($ids[$e['source']], $ids[$e['target']])) { $ends = false; break; }
        }
        if (!$slice || !isset($ids[$entry['node']]) || !$ends || array_diff_key($ids, $graph_nodes)
            || $bytes > 120 * 1024 || (($slice['more'] ?? 0) > 0) !== (($direct[$entry['node']] ?? 0) > 40)) {
            $bad[] = $fid . ' ' . $entry['name'];
        }
    }
    $check('every map slice holds its root and only graph names, under 120 KB', !$bad,
        $bad ? implode('; ', array_slice($bad, 0, 5)) : count($drawn) . ' slices, largest ' . $largest[1] . ' at ' . (int) ($largest[0] / 1024) . ' KB');
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
    if (in_array('network', $m[1], true)) {
        // The map above the list (Phase 4.1): a hidden figure whose JSON is
        // the slice for this facility's name on the map.
        $embed_ok = preg_match('#<figure class="kop-network-embed" data-kop-network-embed hidden>.*?<script type="application/json" class="kop-network-embed__data">(.*?)</script>#s', $html, $em);
        $slice = $embed_ok ? json_decode($em[1], true) : null;
        $drawn_node = kop_network_map_facility_connections()[$id]['node'] ?? '';
        $check("  $id network section carries the map", is_array($slice) && ($slice['root'] ?? '') === $drawn_node
            && in_array($drawn_node, array_column($slice['nodes'] ?? array(), 'id'), true)
            && !empty($GLOBALS['kop_network_embed']),
            is_array($slice) ? count($slice['nodes']) . ' names, ' . (int) (strlen($em[1]) / 1024) . ' KB' : 'no embed JSON');
        unset($GLOBALS['kop_network_embed']);
    }
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

// Research tags (seeds/research-facility-tags.json). Applied by the real
// deploy step to a TEMP copy of wpdl_postmeta, which shadows the mirror's
// table for this connection only, so nothing is written to the mirror.
echo "\n-- Research tags --\n";
$seed = json_decode((string) file_get_contents(dirname(__DIR__) . '/seeds/research-facility-tags.json'), true);
$check('research tag seed parses', is_array($seed) && !empty($seed['documents']));
$seed_ids = array();
foreach ((array) ($seed['documents'] ?? array()) as $sd) {
    $seed_att = (int) $sd['attachment_id'];
    $check("seed attachment $seed_att is a document in the mirror",
        (string) $wpdb->get_var("SELECT post_type FROM wpdl_posts WHERE ID = $seed_att") === 'attachment');
    $bad = array();
    foreach ($sd['facilities'] as $sf) {
        $seed_ids[(int) $sf['id']] = $seed_att;
        if (!$wpdb->get_var('SELECT 1 FROM facilities_v2 WHERE id = ' . (int) $sf['id'])) $bad[] = $sf['id'] . ' (no facilities_v2 row)';
        if (!preg_match('/^\d+(-\d+)?(, \d+(-\d+)?)*$/', (string) $sf['pages'])) $bad[] = $sf['id'] . ' (pages "' . $sf['pages'] . '")';
    }
    $check('every seeded facility exists and cites pages', !$bad, $bad ? implode('; ', array_slice($bad, 0, 5)) : count($sd['facilities']) . ' facilities');
}
require_once dirname(__DIR__) . '/inc/admin.php';
require_once dirname(__DIR__) . '/inc/research-library.php';
function get_post_type($id) { global $wpdb; return (string) $wpdb->get_var('SELECT post_type FROM wpdl_posts WHERE ID = ' . (int) $id); }
function add_post_meta($id, $key, $value) {
    global $pdo;
    $st = $pdo->prepare('INSERT INTO temp.wpdl_postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)');
    return $st->execute(array((int) $id, $key, is_array($value) ? serialize($value) : (string) $value));
}
function update_post_meta($id, $key, $value) {
    global $pdo;
    $pdo->prepare('DELETE FROM temp.wpdl_postmeta WHERE post_id = ? AND meta_key = ?')->execute(array((int) $id, $key));
    return add_post_meta($id, $key, $value);
}
function delete_post_meta($id, $key, $value = '') {
    global $pdo;
    $sql = 'DELETE FROM temp.wpdl_postmeta WHERE post_id = ? AND meta_key = ?' . ($value !== '' ? ' AND meta_value = ?' : '');
    return $pdo->prepare($sql)->execute($value !== '' ? array((int) $id, $key, (string) $value) : array((int) $id, $key));
}
$pdo->exec('CREATE TEMP TABLE wpdl_postmeta AS SELECT * FROM main.wpdl_postmeta');
// Start from a site the seed has not reached (the mirror is the live site, already tagged),
// plus the leftover of two runs at once: one facility tagged twice.
$seed_atts = array_values(array_unique($seed_ids));
$pdo->exec("DELETE FROM temp.wpdl_postmeta WHERE meta_key = 'kop_research_facilities' AND post_id IN (" . implode(',', array_map('intval', $seed_atts)) . ')');
$twice = array_key_first($seed_ids);
add_post_meta($seed_ids[$twice], 'kop_research_facilities', $twice);
add_post_meta($seed_ids[$twice], 'kop_research_facilities', $twice);
$index_untagged = kop_facility_pages_index(true);
$research_summary = kop_apply_research_facility_tags();
$tagged = array_map('intval', $wpdb->get_col("SELECT meta_value FROM wpdl_postmeta WHERE meta_key = 'kop_research_facilities' AND post_id IN ("
    . implode(',', array_map('intval', $seed_atts)) . ')'));
$check('the deploy step tags every seeded facility once', count($tagged) === count($seed_ids) && !array_diff(array_keys($seed_ids), $tagged),
    implode(', ', $research_summary) . ', ' . count($tagged) . ' tags');
$again = kop_apply_research_facility_tags();
$check('a second run adds nothing', count($wpdb->get_col("SELECT meta_value FROM wpdl_postmeta WHERE meta_key = 'kop_research_facilities' AND post_id IN ("
    . implode(',', array_map('intval', $seed_atts)) . ')')) === count($seed_ids), implode(', ', $again));
$research_index = kop_facility_pages_index(true);
$check('the tags change the index fingerprint', $research_index['fingerprint'] !== $index_untagged['fingerprint']);
$no_page = array();
foreach ($seed_ids as $fid => $att) if (!isset($research_index['ids'][$fid])) $no_page[] = $fid;
$check('every tagged facility has a page', !$no_page, $no_page ? implode(', ', $no_page) : count($seed_ids) . ' pages, ' . (count($research_index['ids']) - $eligible) . ' of them new');
$sample = 12688;   // Acadia Montana, printed p. 31 = PDF page 32
if (isset($seed_ids[$sample]) && isset($research_index['ids'][$sample]) && $research_index['ids'][$sample]['editorial'] === '') {
    $data = kop_facility_page_data($sample);
    $GLOBALS['kop_facility_page'] = $data;
    ob_start();
    include dirname(__DIR__) . '/templates/facility-page.php';
    $html = ob_get_clean();
    file_put_contents($out_dir . '/' . $data['slug'] . '.html', $html);
    $check('a tagged facility page lists the report with its pages', strpos($html, 'Named on p. 31') !== false && strpos($html, '.pdf#page=32') !== false
        && strpos($html, '>Warehouses of Neglect: How Taxpayers') !== false,
        $out_dir . '/' . $data['slug'] . '.html');
}
// The program index feed carries the same documents (kop_attach_research_to_projects).
$feed = array('op' => array('id' => 0, 'source_table' => 'facilities_master', 'data' => array('facilities' => array(
    array('facility_id' => $sample), array('facility_id' => 999999999), array('name' => 'no id'),
))));
kop_attach_research_to_projects($feed);
$fed = $feed['op']['data']['facilities'];
$check('the program index feed lists a tagged facility\'s report with its pages',
    isset($fed[0]['research'][0]) && $fed[0]['research'][0]['pages'] === 'p. 31' && strpos($fed[0]['research'][0]['url'], '.pdf#page=32') !== false
        && strpos($fed[0]['research'][0]['title'], 'Warehouses of Neglect') === 0 && !isset($fed[1]['research']) && !isset($fed[2]['research']),
    isset($fed[0]['research']) ? count($fed[0]['research']) . ' document(s)' : 'none attached');
$pdo->exec('DROP TABLE temp.wpdl_postmeta');
kop_facility_pages_index(true);

// Sources: a staff entry names where it came from, and a fact the notes cite links to its page.
echo "-- Sources --\n";
$src_doc_staff = array('notableStaff' => array(
    array('name' => 'Jane Doe', 'role' => 'Clinical Director (2025, r/troubledteens wiki)', 'pastJobs' => '',
        'source' => 'r/troubledteens wiki, page "Test Academy" (as of 2025-12-18)', 'sourceUrl' => 'https://www.reddit.com/r/troubledteens/wiki/index/test'),
    array('name' => 'John Roe', 'role' => 'Therapist', 'pastJobs' => ''),
));
$src_items = kop_facility_pages_staff_items(kop_facility_normalize(array('staff' => $src_doc_staff))['staff']);
$check('a staff entry keeps its source through the normalizer', ($src_items['notableStaff'][0]['source'] ?? '') === 'r/troubledteens wiki, as of Dec 2025'
    && ($src_items['notableStaff'][0]['url'] ?? '') === 'https://www.reddit.com/r/troubledteens/wiki/index/test' && ($src_items['notableStaff'][1]['source'] ?? 'x') === '',
    wp_json_encode($src_items));
$src_notes = kop_facility_pages_note_sources(array(
    'Capacity: 35 (as of 2020) (r/troubledteens wiki, page "Test Academy" (as of 2025-12-18): https://www.reddit.com/r/troubledteens/wiki/index/test)',
    'Start year: 1998 (Woodbury Reports, May 2007 (#153), p. 20: https://kidsoverprofits.org/wp-content/uploads/w.pdf#page=20)',
    'Past name: Old Name (HEAL, staff list for X (archived 2012-05-17)) https://web.archive.org/web/2012/x.htm',
    'An unrelated note (Woodbury Reports, May 2007, p. 3: https://example.org/a)',
));
$check('the notes give each fact its dated source', ($src_notes['Capacity'][0]['source'] ?? '') === 'r/troubledteens wiki, as of Dec 2025'
    && ($src_notes['Capacity'][0]['url'] ?? '') === 'https://www.reddit.com/r/troubledteens/wiki/index/test'
    && ($src_notes['Operated'][0]['source'] ?? '') === 'Woodbury Reports, May 2007' && ($src_notes['formerly'][0]['source'] ?? '') === 'HEAL, archived May 2012' &&strpos($src_notes['Operated'][0]['url'] ?? '', '#page=20') !== false
    && ($src_notes['formerly'][0]['url'] ?? '') === 'https://web.archive.org/web/2012/x.htm' && count($src_notes) === 3,
    wp_json_encode($src_notes));
if ($picks) {
    $data = kop_facility_page_data($picks[0]);
    $data['staff'] = $src_items;
    $data['facts'][] = array('label' => 'Capacity', 'value' => '35');
    $data['fact_sources'] = $src_notes;
    $GLOBALS['kop_facility_page'] = $data;
    ob_start();
    include dirname(__DIR__) . '/templates/facility-page.php';
    $html = ob_get_clean();
    $check('the page links a staff entry and a fact to their sources',
        preg_match('#kop-fp-person-name">Jane Doe</p>\s*<p class="kop-fp-person-role">Clinical Director[^<]*<span class="kop-fp-src">Source: <a class="kop-citation-link"[^>]*href="https://www\.reddit\.com/r/troubledteens/wiki/index/test"#', $html)
        && preg_match('#<dd class="kop-fp-src"><a class="kop-citation-link"[^>]*href="https://www\.reddit\.com/r/troubledteens/wiki/index/test">source</a>#', $html)
        && preg_match('#kop-fp-person-name">John Roe</p>\s*<p class="kop-fp-person-role">Therapist</p>#', $html)
        && strpos($html, '>r/troubledteens wiki, as of Dec 2025</a>') !== false);
    // Two citations of one issue are told apart by page; of one date with no page, by number.
    $data['fact_sources']['Capacity'] = kop_facility_pages_note_sources(array(
        'Capacity: 35 (Woodbury Reports, May 2007 (#153), p. 20: https://example.org/w.pdf#page=20)',
        'Capacity: 40 (Woodbury Reports, May 2007 (#153), p. 31: https://example.org/w.pdf#page=31)',
    ))['Capacity'];
    $data['fact_sources']['Operated'] = array(
        array('source' => 'Fornits, Mar 2005', 'cite' => 'Fornits forum, post by a, March 2005', 'url' => 'https://example.org/1'),
        array('source' => 'Fornits, Mar 2005', 'cite' => 'Fornits forum, post by b, March 2005', 'url' => 'https://example.org/2'),
    );
    $data['facts'][] = array('label' => 'Operated', 'value' => '1998');
    $GLOBALS['kop_facility_page'] = $data;
    ob_start();
    include dirname(__DIR__) . '/templates/facility-page.php';
    $html = ob_get_clean();
    // At a glance links the word "source" (source 1, source 2); each citation is in its link's preview.
    $check('two sources of one fact are two "source" links, each citation in its preview',
        preg_match('#data-kop-citation-preview="Woodbury Reports, May 2007[^"]*p\. 20"[^>]*>source 1</a>, <a#', $html)
        && preg_match('#data-kop-citation-preview="Woodbury Reports, May 2007[^"]*p\. 31"[^>]*>source 2</a>#', $html)
        && preg_match('#post by a, March 2005"[^>]*>source 1</a>, <a[^>]*post by b, March 2005"[^>]*>source 2</a>#', $html)
        && strpos($html, '<dd class="kop-fp-src">Source: ') === false);
}

// The livelier sections: incidents as a timeline, people across records, serious findings, news pictures.
echo "-- Highlights --\n";
$inc = kop_facility_pages_incidents(array(
    'September 2024: staff member arrested for striking a 15-year-old during a restraint',
    'Reported April 2004: Abuse: Former students report physical abuse including forced drugging. (Fornits forum, post by cherish wisdom, April 2004: https://www.fornits.com/phpbb/index.php?topic=5238.0)',
    '1974: shut down over child abuse and neglect allegations',
    'Something with no date at all',
));
$check('incidents read as a dated timeline, oldest first, citation split off',
    count($inc) === 4 && $inc[0]['when'] === '1974' && $inc[1]['when'] === 'Reported April 2004' && $inc[1]['kind'] === 'Abuse'
    && strpos($inc[1]['text'], 'Former students') === 0 && $inc[1]['url'] === 'https://www.fornits.com/phpbb/index.php?topic=5238.0'
    && $inc[1]['source'] !== '' && $inc[2]['when'] === 'September 2024' && $inc[3]['when'] === '' && $inc[0]['text'] === 'Shut down over child abuse and neglect allegations',
    wp_json_encode($inc));
// Laurel Ridge after its two records merged: wiki dates (2000-02-06), one death written twice.
$inc = kop_facility_pages_incidents(array(
    '2000-02-06: Death: On February 6, 2000 a 9-year-old boy, one month into his stay, died after being restrained face down; the death was attributed to a heart attack resulting from the restraint. (r/troubledteens wiki, page "Laurel Ridge Treatment Center" (as of 2025-12-18))',
    'Reported 2025-12: Abuse: Survivors report verbal and physical abuse at Laurel Ridge. (r/troubledteens wiki, page "Laurel Ridge Treatment Center" (as of 2025-12-18))',
    '2006-04-09: Death: On April 9, 2006 a 16-year-old sent from Alaska died by suicide. (r/troubledteens wiki, page "Laurel Ridge Treatment Center" (as of 2025-12-18))',
    '2000: Restraint: In 2000 a 9-year-old boy died of a heart attack a day after employees held him facedown at Laurel Ridge Treatment Center in San Antonio. (r/troubledteens wiki, page "Brown Schools Inc." (as of 2026-01-07))',
    '1997-08-18: Death: On August 18, 1997 a 16-year-old girl died during a violent face-down restraint. (r/troubledteens wiki, page "Laurel Ridge Treatment Center" (as of 2025-12-18))',
    '2006: a 16-year-old girl ran away and was found two days later',
));
$check('wiki dates read and sort; one event written twice shows once, both sources kept',
    array_column($inc, 'when') === array('August 18, 1997', 'February 6, 2000', '2006', 'April 9, 2006', 'Reported December 2025')
    && count($inc[1]['also'] ?? array()) === 1 && strpos($inc[1]['text'], 'one month into his stay') !== false,
    wp_json_encode(array_column($inc, 'when')));
$cited = kop_facility_pages_cited_html('Ownership: sold in April 2003 (Form 10-K for 2003, filed March 2004: https://www.sec.gov/x/g87995e10vk.htm; FTC notice: https://www.ftc.gov/node/10021)');
$check('a note\'s web addresses read as "source" links, never the address',
    substr_count($cited, '>source</a>') === 2 && strpos($cited, 'filed March 2004, <a') !== false && !preg_match('#>https?://#', $cited)
    && kop_facility_pages_cited_html('No link <here>') === 'No link &lt;here&gt;', $cited);
$check('one person, however the records spell them',
    kop_facility_pages_person_key('Dr. Robert H. Crist, MD') === 'robert crist' && kop_facility_pages_person_key('Admissions: Jane Doe') === 'jane doe'
    && kop_facility_pages_person_key('Gerald "Jerry" Rushing') === 'gerald rushing' && kop_facility_pages_person_key('Cher') === '');
$career = kop_facility_pages_person_career('Nobody Atall', 'Admissions Director - Sunrise Academy (2007); Staff - Cinnamon Hills Youth Crisis Center (2001) [joined 2001-03]', 0);
$check('past jobs split into place, role and years',
    count($career) === 2 && $career[0]['place'] === 'Sunrise Academy' && $career[0]['role'] === 'Admissions Director' && $career[0]['years'] === '2007'
    && $career[1]['place'] === 'Cinnamon Hills Youth Crisis Center', wp_json_encode($career));
$check('nicknames fold into one person', kop_facility_pages_person_key('Steve Roach') === kop_facility_pages_person_key('Steven Roach')
    && kop_facility_pages_person_key('Clint Dorny') === kop_facility_pages_person_key('Clinton Dorny')
    && kop_facility_pages_person_key('Jack Williams') !== kop_facility_pages_person_key('John Williams'));
// Everyone at two or more places, staff lists and the network map together.
$places = array();
foreach (kop_facility_pages_people_index() as $key => $hits) foreach ($hits as $h) $places[$key]['f' . $h[0]] = true;
foreach (kop_facility_pages_map_people() as $key => $hits) foreach ($hits as $h) $places[$key][$h[2] ? 'f' . $h[2] : 'n' . $h[0]] = true;
$multi = 0;
foreach ($places as $p) if (count($p) > 1) $multi++;
printf("  people: %d on staff lists, %d on the network map, %d in all; at two or more places: %d\n",
    count(kop_facility_pages_people_index()), count(kop_facility_pages_map_people()), count($places), $multi);
$check('hundreds of people worked at more than one place', $multi > 500, (string) $multi);
$trails = kop_facility_page_data(11660);
$jj = null;
$map_only = array();
foreach (array('administrator', 'notableStaff') as $k) foreach ($trails['staff'][$k] ?? array() as $p) {
    if (($p['name'] ?? '') === 'Jeff Johnson') $jj = $p;
    if (!empty($p['from_map']) && !empty($p['career'])) $map_only[] = $p['name'];
}
$check('Jeff Johnson at Trails Carolina carries his other programs', $jj && count($jj['career']) >= 5, $jj ? count($jj['career']) . ' other places' : 'not found');
$check('people only the network map ties to a program get staff cards with their careers', count($map_only) > 0, implode(', ', array_slice($map_only, 0, 6)));
$people_in_network = 0;
foreach ($trails['network']['groups'] ?? array() as $g) foreach ($g['items'] as $it) if ($it['name'] === 'Jeff Johnson') $people_in_network++;
$check('the Network section no longer repeats the people', $people_in_network === 0);
$prov = kop_facility_page_data(10371);
if ($prov) {
    $with_career = 0;
    foreach (array('administrator', 'notableStaff') as $k) foreach ($prov['staff'][$k] ?? array() as $p) if (!empty($p['career'])) $with_career++;
    $check('Provo Canyon School: staff carry their other roles', $with_career > 0, $with_career . ' people');
    $v = $prov['inspections']['violations'] ?? array();
    $check('Provo Canyon School: approved serious findings, no markup in the words', count($v) > 0 && strpos(implode(' ', array_column($v, 'excerpt')), '<br') === false, count($v) . ' findings');
}
$parsed = kop_news_images_parse('<html><head><meta content="/img/a.jpg" property="og:image"><meta name="twitter:image" content="https://cdn.example.org/b.jpg">'
    . '<link rel="apple-touch-icon" sizes="180x180" href="/icon-180.png"><link rel="icon" href="/favicon.svg">'
    . '<script type="application/ld+json">{"@type":"NewsArticle","image":{"@type":"ImageObject","url":"https://cdn.example.org/c.jpg"},"publisher":{"@type":"Organization","logo":{"url":"https://cdn.example.org/logo.png"}}}</script></head></html>',
    'https://news.example.org/story/1');
$check('news page pictures: share image first, publisher logo and touch icon as logos',
    ($parsed['photo'][0] ?? '') === 'https://news.example.org/img/a.jpg' && in_array('https://cdn.example.org/c.jpg', $parsed['photo'], true)
    && ($parsed['logo'][0] ?? '') === 'https://cdn.example.org/logo.png' && in_array('https://news.example.org/icon-180.png', $parsed['logo'], true)
    && !in_array('https://cdn.example.org/logo.png', $parsed['photo'], true), wp_json_encode($parsed));

// A merged Facility Profile (kop_facility_pages_merged_profiles()): Hyde School's
// post prints on its facility page, unchanged, and the post 301s there.
echo "\n-- Merged profile (Hyde School) --\n";
$hyde_post = (int) $wpdb->get_var("SELECT ID FROM wpdl_posts WHERE post_name = 'hyde' AND post_status = 'publish' AND post_type = 'post'");
$hyde_id = 0;
foreach ($index['ids'] as $id => $e) if ((int) ($e['profile_post'] ?? 0) === $hyde_post) $hyde_id = $id;
$check('Hyde School record carries its profile post and no editorial redirect', $hyde_post > 0 && $hyde_id > 0 && $index['ids'][$hyde_id]['editorial'] === '', "post $hyde_post, record $hyde_id");
if ($hyde_id > 0) {
    $r = $route($index['ids'][$hyde_id]['slug']);
    $check('Hyde School facility page renders instead of redirecting', isset($r['page']) && $r['status'] === 200, json_encode($r));
    $check('the post now 301s to the facility page', kop_facility_pages_merged_profile_url($hyde_post) === kop_facility_pages_url_for_slug($index['ids'][$hyde_id]['slug']));
    $check('kop_facility_page_url() points at the facility page', kop_facility_page_url($hyde_id) === kop_facility_pages_url_for_slug($index['ids'][$hyde_id]['slug']));
    $data = kop_facility_page_data($hyde_id);
    $GLOBALS['kop_facility_page'] = $data;
    ob_start();
    include dirname(__DIR__) . '/templates/facility-page.php';
    $html = ob_get_clean();
    file_put_contents($out_dir . '/' . $data['slug'] . '.html', $html);
    $content = (string) $wpdb->get_var($wpdb->prepare('SELECT post_content FROM wpdl_posts WHERE ID = %d', $hyde_post));
    // Every paragraph, heading and list item of the post is on the page word for word, and every link preview as a card.
    $norm = static function ($t) { return preg_replace('/\s+/u', '', html_entity_decode(strip_tags((string) $t), ENT_QUOTES, 'UTF-8')); };
    $page_text = $norm($html);
    preg_match_all('/<(p|h2|h3|li)\b[^>]*>(.*?)<\/\1>/s', $content, $tm);
    $missing = array();
    foreach ($tm[2] as $t) {
        $n = $norm($t);
        if ($n === '' || $n === 'Backtoindex' || strpos($n, 'Lastupdated:') === 0) continue;
        if (strpos($t, 'href="#') !== false && substr_count($t, 'href="#') > 3) continue; // the Index box: the jump links now
        // A rail fact keeps its words; its label loses the trailing colon ("Partner schools:" is the dt "Partner schools").
        if (strpos($page_text, $n) === false && strpos($page_text, preg_replace('/^([^:]{3,40}):/u', '$1', $n)) === false) $missing[] = mb_substr(strip_tags($t), 0, 60);
    }
    $check('every text block of the post is on the page unchanged', $content !== '' && !$missing, count($tm[2]) . ' blocks; missing: ' . implode(' | ', array_slice($missing, 0, 3)));
    preg_match_all('/"encoded":"([A-Za-z0-9+\/=]+)"/', $content, $em);
    $cards_missing = 0;
    foreach ($em[1] as $enc) {
        $d = json_decode((string) base64_decode($enc), true);
        if (!empty($d['title']) && strpos($page_text, $norm($d['title'])) === false) $cards_missing++;
    }
    $check('every link preview is a card on the page', count($em[1]) > 0 && $cards_missing === 0, count($em[1]) . ' previews, ' . $cards_missing . ' missing');
    $check('every video is on the page', substr_count($html, '<iframe') >= substr_count($content, '<iframe'), substr_count($content, '<iframe') . ' videos');
    $check('the post is not printed as one block any more', strpos($html, 'class="entry-content single-content kop-fp-body kop-fp-profile"') === false);
    $check('the post facts are in the rail', strpos($html, 'kop-fp-profile-fact') !== false && strpos($html, 'Wilderness properties') < strpos($html, 'kop-fp-generated-body'));
    $check('the description is the post excerpt', $data['seo_description'] !== $data['summary']);
    preg_match_all('/\sid="([^"]+)"/', $html, $m);
    $dupes = array_keys(array_filter(array_count_values($m[1]), static function ($n) { return $n > 1; }));
    $check('no id is used twice (the post\'s #news/#lawsuits/#related vs the record sections)', !$dupes, implode(', ', $dupes));
    $check('the written sections come first, then lawsuits and news joined with the record', strpos($html, 'id="intro"') < strpos($html, 'id="lawsuits"') && strpos($html, 'id="lawsuits"') < strpos($html, 'id="news"'));
    $check('the jump links name the post\'s sections', strpos($html, 'href="#intro"') !== false && strpos($html, 'href="#news"') !== false);
    $check('old anchors still land (#survivors, #doclibrary)', strpos($html, 'id="survivors"') !== false && strpos($html, 'id="doclibrary"') !== false);
    $check('the page renders to the end after the post (setup_postdata() sets the global $page)', strpos($html, 'kop-fp-footer') !== false && strpos($html, 'id="news"') !== false);
}

// Sitemap entries
$entries = kop_facility_pages_sitemap_entries();
$operator_pages = function_exists('kop_operator_pages_index') ? count(kop_operator_pages_index()['ids']) : 0;
$check('sitemap lists every generated page', count($entries) === $eligible - $editorial + $operator_pages, count($entries) . ' entries, ' . $operator_pages . ' of them parent companies');

echo "\n" . ($failures ? "$failures FAILED" : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
