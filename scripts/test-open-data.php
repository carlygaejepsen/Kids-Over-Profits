<?php
/**
 * Offline check of the open data build (inc/open-data.php) against the SQLite
 * mirror of production (tmp/prod.sqlite). WordPress is stubbed by
 * scripts/kop-test-harness.php; the builder's real SQL runs through
 * PDO/SQLite and writes to tmp/kop-open-data/ instead of uploads. Read-only
 * against the mirror.
 *
 * Checks that every dataset builds, row counts match the published records,
 * nothing private reaches a file (submitter and reviewer fields, unpublished
 * testimony, referrer contact details, survivors named in news), the CSV
 * files are rectangular, and the resumable full-text build gives one
 * readable gzip stream with every report once.
 *
 * Usage (Local's bundled PHP):
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       -d extension=zip -d extension=zlib scripts/test-open-data.php [--db=tmp/prod.sqlite] [--skip-fulltext]
 *
 * Also renders the page to tmp/kop-open-data/page.html for a look in a browser.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'skip-fulltext'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
ini_set('memory_limit', '512M');

function wp_upload_dir() { return array('basedir' => dirname(__DIR__) . '/tmp', 'baseurl' => 'https://example.test/wp-content/uploads'); }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d); }
function wp_date($format, $ts = null) { return date($format, $ts ?? time()); }
function wp_enqueue_style() {}
function the_title() { echo 'Open Data'; }
function has_excerpt() { return false; }
function get_the_excerpt() { return ''; }
function get_the_content() { return ''; }
function the_content() {}
function the_post() {}
function have_posts() { return false; }
function kop_find_template_page_url($t) { return $t === 'page-data.php' ? 'https://example.test/data/' : ''; }

require __DIR__ . '/kop-test-harness.php';
function kop_seed_pdo() { return $GLOBALS['pdo']; }
$GLOBALS['pdo'] = $pdo;
require_once dirname(__DIR__) . '/inc/inspection-highlights.php';
require_once dirname(__DIR__) . '/inc/open-data.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$dir = kop_open_data_dir()['path'];
$check('the build writes into tmp/kop-open-data', realpath(dirname($dir)) === realpath(dirname(__DIR__) . '/tmp') && basename($dir) === 'kop-open-data');
foreach (array_merge(glob("$dir/editions/*/*") ?: array(), glob("$dir/*") ?: array()) as $old) {
    if (is_file($old)) unlink($old);
}
foreach (glob("$dir/editions/*", GLOB_ONLYDIR) ?: array() as $old) rmdir($old);

// ---------------------------------------------------------------------------
echo "-- Build --\n";
$t = microtime(true);
$manifest = kop_open_data_build();
printf("  built %d datasets in %.1fs\n", count($manifest['datasets']), microtime(true) - $t);
$check('no dataset failed', empty($manifest['errors']), json_encode($manifest['errors']));

$expected = array('facilities', 'operators', 'operator-facilities', 'facility-addresses', 'referrers',
    'inspection-facilities', 'inspection-reports', 'severe-findings', 'lawsuits', 'lawsuit-facilities',
    'legislation', 'news', 'news-facilities', 'network-nodes', 'network-edges', 'glossary', 'reporting-directory');
$missing = array_diff($expected, array_keys($manifest['datasets']));
$check('every dataset is in the manifest', !$missing, implode(', ', $missing));
foreach ($manifest['datasets'] as $key => $d) {
    printf("  %-22s %7d rows  %s\n", $key, $d['rows'], implode(' ', array_map(function ($f) { return $f['name'] . ' ' . kop_open_data_size($f['bytes']); }, $d['files'])));
}

$count = function ($sql) use ($pdo) { return (int) $pdo->query($sql)->fetchColumn(); };
$rows = function ($key) use ($manifest) { return (int) ($manifest['datasets'][$key]['rows'] ?? -1); };
$check('facilities: every facilities_v2 row', $rows('facilities') === $count('SELECT COUNT(*) FROM facilities_v2'));
$check('operators: every operator', $rows('operators') === $count('SELECT COUNT(*) FROM wpdl_kop_operators'));
$check('lawsuits: published only', $rows('lawsuits') === $count("SELECT COUNT(*) FROM lawsuits WHERE publication_status = 'published'"));
$check('legislation: published only', $rows('legislation') === $count("SELECT COUNT(*) FROM legislation WHERE publication_status = 'published'"));
$check('news: approved only', $rows('news') === $count("SELECT COUNT(*) FROM news_submissions WHERE status IN ('approved','published')"));
$check('inspection reports: every report', $rows('inspection-reports') === $count('SELECT COUNT(*) FROM inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id'));
list($severe_sql, $severe_params) = kop_ih_severe_query();
$severe = $pdo->prepare("SELECT COUNT(*) FROM ($severe_sql)");
$severe->execute($severe_params);
$check('severe findings: approved severe only, as the Severe Reports page', $rows('severe-findings') === (int) $severe->fetchColumn());
$check('glossary counted by entries', $rows('glossary') > 100);
$check('ZIP written', !empty($manifest['zip']) && file_exists("$dir/" . KOP_OPEN_DATA_ZIP));

// ---------------------------------------------------------------------------
echo "\n-- Privacy --\n";
$private = array('submitted_by', 'reviewer_notes', 'reviewed_by', 'submission_notes', 'submitter_ip', 'review_note', 'survivors_mentioned', 'generated_output');
$leaks = array();
foreach ($manifest['datasets'] as $key => $d) {
    foreach ($d['files'] as $f) {
        $text = file_get_contents("$dir/{$f['name']}");
        foreach ($private as $col) {
            if (strpos($text, '"' . $col . '"') !== false || ($f['format'] === 'csv' && preg_match('/^[^\n]*\b' . $col . '\b/', $text))) {
                $leaks[] = "$key/{$f['format']}: $col";
            }
        }
    }
}
$check('no submitter, reviewer or survivor-name column in any file', !$leaks, implode(' | ', $leaks));

$ref = json_decode(file_get_contents("$dir/referrers.json"), true);
$contact = array();
array_walk_recursive($ref, function ($v, $k) use (&$contact) {
    if (in_array($k, array('phone', 'email', 'phoneNumber', 'emailAddress', 'fax'), true) && $v !== '' && $v !== null) $contact[] = $k;
});
$check('referrers carry no phone, email or fax', !$contact, implode(', ', array_unique($contact)));

$unpublished = 0;
$published = 0;
$walk = function ($value) use (&$walk, &$unpublished, &$published) {
    if (!is_array($value)) return;
    foreach ($value as $k => $v) {
        if ($k === 'survivorTestimony' && is_array($v)) {
            foreach ($v as $entry) {
                if (is_array($entry) && ($entry['publish'] ?? false) === true) $published++; else $unpublished++;
            }
            continue;
        }
        $walk($v);
    }
};
foreach (array('facilities', 'operators', 'referrers') as $key) {
    $walk(json_decode(file_get_contents("$dir/$key.json"), true));
}
$check('only published survivor testimony', $unpublished === 0, "$published published, $unpublished unpublished");

$facilities = json_decode(file_get_contents("$dir/facilities.json"), true);
$check('facility records drop provenance and legacy', !array_filter($facilities, function ($f) { return isset($f['record']['provenance']) || isset($f['record']['legacy']); }));

// ---------------------------------------------------------------------------
echo "\n-- Files --\n";
foreach ($manifest['datasets'] as $key => $d) {
    foreach ($d['files'] as $f) {
        $path = "$dir/{$f['name']}";
        $ok = hash_file('sha256', $path) === $f['sha256'] && filesize($path) === $f['bytes'];
        if (!$ok) $check("$key: manifest checksum matches {$f['name']}", false);
        if ($f['format'] === 'json') {
            if (json_decode(file_get_contents($path), true) === null) $check("$key: {$f['name']} parses", false, json_last_error_msg());
        }
        if ($f['format'] === 'csv') {
            $h = fopen($path, 'rb');
            $width = null;
            $lines = 0;
            $ragged = 0;
            while (($line = fgetcsv($h, 0, ',', '"', '')) !== false) {
                if ($width === null) { $width = count($line); continue; }
                $lines++;
                if (count($line) !== $width) $ragged++;
            }
            fclose($h);
            if ($ragged || $lines !== $d['rows']) $check("$key: CSV has one full row per record", false, "$lines lines, $ragged ragged, {$d['rows']} rows");
        }
    }
}
$check('every file matches its manifest entry, parses, and every CSV is rectangular', true);

$zip = new ZipArchive();
$zip->open("$dir/" . KOP_OPEN_DATA_ZIP);
$names = array();
for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
$zip->close();
$want = array('README.txt');
foreach ($manifest['datasets'] as $d) foreach ($d['files'] as $f) $want[] = $f['name'];
$check('ZIP holds the README and every file', !array_diff($want, $names), implode(', ', array_diff($want, $names)));

// ---------------------------------------------------------------------------
echo "\n-- Editions --\n";
$month = gmdate('Y-m', strtotime($manifest['generated_at']));
$ed_dir = kop_open_data_editions_dir() . '/' . $month;
$editions = kop_open_data_editions();
$check("this month's edition was saved", count($editions) === 1 && $editions[0]['edition'] === $month);
$check('edition ZIP is a byte-for-byte copy', hash_file('sha256', "$ed_dir/" . KOP_OPEN_DATA_ZIP) === $manifest['zip']['sha256']
    && $editions[0]['zip']['sha256'] === $manifest['zip']['sha256']);
$check('edition records rows per dataset', ($editions[0]['rows']['facilities'] ?? -1) === $rows('facilities'));
$frozen = file_get_contents("$ed_dir/edition.json");
$frozen_zip = hash_file('sha256', "$ed_dir/" . KOP_OPEN_DATA_ZIP);
$later = $manifest;
$later['generated_at'] = gmdate('c', strtotime($manifest['generated_at']) + 60);
kop_open_data_save_edition($later);
$check('a later build the same month leaves the edition alone', file_get_contents("$ed_dir/edition.json") === $frozen
    && hash_file('sha256', "$ed_dir/" . KOP_OPEN_DATA_ZIP) === $frozen_zip);

if (!isset($args['skip-fulltext'])) {
    echo "\n-- Full text --\n";
    $GLOBALS['kop_test_fulltext_slices'] = 0;
    $t = microtime(true);
    do {
        $GLOBALS['kop_test_fulltext_slices']++;
        $done = kop_open_data_build_fulltext(2);
    } while (!$done && $GLOBALS['kop_test_fulltext_slices'] < 1000);
    printf("  %d slices in %.1fs\n", $GLOBALS['kop_test_fulltext_slices'], microtime(true) - $t);
    $manifest = kop_open_data_manifest();
    $file = "$dir/" . KOP_OPEN_DATA_FULLTEXT;
    $check('full text finished and is in the manifest', $done && !empty($manifest['full_text']) && file_exists($file));
    $check('took more than one slice (the resume path ran)', $GLOBALS['kop_test_fulltext_slices'] > 1);

    $gz = gzopen($file, 'rb');
    $lines = 0;
    $bad = 0;
    $ids = array();
    while (($line = gzgets($gz)) !== false) {
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['raw_content'])) { $bad++; continue; }
        $ids[$row['id']] = ($ids[$row['id']] ?? 0) + 1;
        $lines++;
    }
    gzclose($gz);
    $total = $count('SELECT COUNT(*) FROM inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id');
    printf("  %d reports, %s\n", $lines, kop_open_data_size(filesize($file)));
    $check('every line is a report with its text', $bad === 0, "$bad bad lines");
    $check('every report once', $lines === $total && count($ids) === $total, "$lines lines, " . count($ids) . " ids, $total in the mirror");
    $check('manifest row count matches', (int) $manifest['full_text']['rows'] === $total);

    $edition = kop_open_data_editions()[0];
    $check("full text kept in this quarter's edition", !empty($edition['full_text'])
        && hash_file('sha256', "$ed_dir/" . KOP_OPEN_DATA_FULLTEXT) === $manifest['full_text']['sha256']);
    $kept = file_get_contents("$ed_dir/edition.json");
    kop_open_data_save_edition_fulltext($manifest);
    $check('a second full-text build the same quarter adds no copy', file_get_contents("$ed_dir/edition.json") === $kept);
}

// ---------------------------------------------------------------------------
echo "\n-- Page --\n";
ob_start();
include dirname(__DIR__) . '/templates/page-open-data.php';
$html = ob_get_clean();
file_put_contents("$dir/page.html", str_replace('facility-profile.css', 'open-data.css', $html));
$check('page lists every dataset', substr_count($html, 'class="kop-od-item"') === count($manifest['datasets']));
$check('page links the ZIP', strpos($html, KOP_OPEN_DATA_ZIP) !== false);
$check('page lists the past editions', strpos($html, 'Past editions') !== false && strpos($html, 'editions/' . $month . '/') !== false);
$check('page states the license', strpos($html, 'Attribution-ShareAlike 4.0') !== false);
echo "  wrote $dir/page.html\n";

echo "\n" . ($failures ? "$failures FAILED" : 'ALL PASSED') . "\n";
exit($failures ? 1 : 0);
