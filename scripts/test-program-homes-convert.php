<?php
/**
 * Parent companies that are one program of homes (inc/program-homes-convert.php)
 * against the SQLite mirror of production: which companies are suggested and
 * what each home is called, converting Dimondale (five same-named homes), a
 * one-record company and a company with a written history, the program page
 * and its History section, the old /operator/ address, and Undo. Last, every
 * suggestion is converted and undone, and the tables must come back exactly as
 * they were.
 *
 * The tables a conversion writes are copied into a temp database; everything
 * else is read from tmp/prod.sqlite attached beside it, so the mirror is never
 * written.
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-program-homes-convert.php [--list] [--out=tmp/program-homes-convert]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('out::', 'list'));
$prod = dirname(__DIR__) . '/tmp/prod.sqlite';
$out_dir = $args['out'] ?? (dirname(__DIR__) . '/tmp/program-homes-convert');
if (!file_exists($prod)) {
    fwrite(STDERR, "No mirror at $prod (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);

$db_path = sys_get_temp_dir() . '/kop-program-homes-convert-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->exec('ATTACH DATABASE ' . $copy->quote($prod) . ' AS prod');
$tables = array('facilities_v2', 'wpdl_kop_facility_identity', 'wpdl_kop_operator_facilities', 'wpdl_kop_facility_locations',
    'wpdl_kop_operators', 'wpdl_kop_operator_links', 'news_facility_links', 'wpdl_kop_program_homes', 'wpdl_kop_program_groups');
foreach ($tables as $t) {
    $sql = $copy->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) {
        fwrite(STDERR, "The mirror has no $t (sync it).\n");
        exit(2);
    }
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.$t SELECT * FROM prod.$t");
}
// On production these columns are generated from json_data; here a trigger fills them.
$copy->exec("CREATE TRIGGER facilities_v2_cols AFTER INSERT ON facilities_v2 BEGIN
    UPDATE facilities_v2 SET name = json_extract(NEW.json_data, '$.identification.name'),
        name_key = json_extract(NEW.json_data, '$.identification.nameKey'),
        state = json_extract(NEW.json_data, '$.location.state'), city = json_extract(NEW.json_data, '$.location.city'),
        country = json_extract(NEW.json_data, '$.location.country'), status = json_extract(NEW.json_data, '$.operatingPeriod.status'),
        facility_type = json_extract(NEW.json_data, '$.facilityDetails.type'),
        start_year = json_extract(NEW.json_data, '$.operatingPeriod.startYear'), end_year = json_extract(NEW.json_data, '$.operatingPeriod.endYear')
    WHERE id = NEW.id; END");
$copy->exec('CREATE TABLE wpdl_kop_program_conversions (operator_id INTEGER PRIMARY KEY, program_id INTEGER NOT NULL, slug TEXT NOT NULL DEFAULT \'\',
    name TEXT NOT NULL DEFAULT \'\', operator_json TEXT NOT NULL, undo_json TEXT NOT NULL, converted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    converted_by INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$copy = null;

$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($prod) . ' AS prod');
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) {
    if (!is_string($v)) return $v;
    $d = json_decode($v);
    return is_string($d) ? $d : $v;
}, 1);

// SHOW TABLES sees the attached mirror too; query() runs any statement.
$GLOBALS['wpdb'] = $wpdb = new class($pdo) extends wpdb {
    private $db;
    public function __construct(PDO $pdo) { parent::__construct($pdo); $this->db = $pdo; }
    private function fix($sql) {
        if (preg_match("/^\s*SHOW TABLES LIKE\s+('[^']*')/i", $sql, $m)) {
            return "SELECT name FROM (SELECT name FROM main.sqlite_master WHERE type = 'table' UNION SELECT name FROM prod.sqlite_master WHERE type = 'table') WHERE name LIKE $m[1]";
        }
        return $sql;
    }
    public function get_var($sql, $x = 0, $y = 0) { return parent::get_var($this->fix($sql), $x, $y); }
    public function get_results($sql, $output = OBJECT) { return parent::get_results($this->fix($sql), $output); }
    public function query($sql) { return $this->db->exec($sql); }
};

if (file_exists(dirname(__DIR__) . '/inc/citations.php')) require_once dirname(__DIR__) . '/inc/citations.php';
require_once dirname(__DIR__) . '/inc/operator-history.php';
require_once dirname(__DIR__) . '/inc/program-homes.php';
require_once dirname(__DIR__) . '/inc/program-homes-convert.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$render = function ($data) {
    $GLOBALS['kop_facility_page'] = $data;
    ob_start();
    include dirname(__DIR__) . '/templates/facility-page.php';
    return ob_get_clean();
};
// Every table a conversion touches, as rows, for the exact-undo check.
$snapshot = function () use ($pdo) {
    $out = array();
    foreach (array('wpdl_kop_operators' => 'id', 'wpdl_kop_operator_facilities' => 'operator_id, facility_id', 'wpdl_kop_operator_links' => 'operator_id, link_kind, link_id',
        'wpdl_kop_program_homes' => 'home_id', 'wpdl_kop_program_groups' => 'program_id', 'news_facility_links' => 'news_id, facility_id',
        'facilities_v2' => 'id', 'wpdl_kop_program_conversions' => 'operator_id') as $t => $order) {
        $cols = $t === 'facilities_v2' ? 'id, unique_name, json_data' : ($t === 'wpdl_kop_program_homes' ? 'home_id, program_id, home_name' : ($t === 'wpdl_kop_program_groups' ? 'program_id, created_record' : '*'));
        $out[$t] = md5(json_encode($pdo->query("SELECT $cols FROM main.$t ORDER BY $order")->fetchAll(PDO::FETCH_NUM)));
    }
    return $out;
};
$opts = array('pdo' => $pdo, 'prefix' => 'wpdl_', 'skip_memberships' => true);
$by_id = function (array $list, $id) {
    foreach ($list as $s) if ($s['operator_id'] === $id) return $s;
    return null;
};

// ---------------------------------------------------------------------------
echo "-- Home names --\n";
foreach (array(
    array('Dimondale Adolescent Care Facility', 'Dimondale Adolescent Care Facility', 'Carson', 'Carson'),
    array('Pacific Autism Center for Education', 'Pacific Autism Center for Education', "Lamar House \u{2013} Sunnyvale", 'Lamar House'),
    array('NeuroRestorative Bears House', 'NeuroRestorative', 'Normal', 'Bears House'),
    array('New Hope Of Arizona, Inc', 'New Hope Of Arizona, Inc', 'Buckeye', 'Buckeye'),
    array("Acme Youth \u{2013} North", 'Acme Youth, Inc.', 'Austin', 'North'),
) as $c) {
    $got = kop_phc_home_name($c[0], $c[1], $c[2]);
    $check("home of '{$c[0]}' in {$c[2]}", $got === $c[3], $got);
}
$check('program name drops ", Inc"', kop_phc_program_name('New Hope Of Arizona, Inc') === 'New Hope Of Arizona');

// ---------------------------------------------------------------------------
echo "\n-- Suggestions --\n";
$sugg = kop_phc_suggestions();
printf("  %d companies are one program\n", count($sugg));
foreach ($sugg as $s) {
    printf("  %-45s %s %2d %-6s history:%-9s%s\n", $s['name'], $s['state'], count($s['homes']), $s['kind'], $s['history'] ?: '-', $s['existing'] ? ' existing #' . $s['existing']['id'] : '');
    if (isset($args['list'])) {
        foreach ($s['homes'] as $h) printf("        #%d %s -> %s\n", $h['id'], $h['name'] . ', ' . $h['city'], $h['home_name']);
        foreach ($s['warnings'] as $w) echo "        ! $w\n";
    }
}
$dim = $by_id($sugg, 5748);
$check('Dimondale is suggested with its five homes, named by town', $dim && $dim['kind'] === 'homes' && count($dim['homes']) === 5
    && array_column($dim['homes'], 'home_name') === array('Carson', 'Gardena', 'Hawthorne', 'Lancaster', 'Long Beach'), $dim ? implode(', ', array_column($dim['homes'], 'home_name')) : 'missing');
$check('Dimondale carries its draft history', $dim && $dim['history'] === 'draft');
$neuro = $by_id($sugg, 15);
$check('NeuroRestorative is suggested, warned that it is based outside Illinois', $neuro && preg_grep('/Dedham/', $neuro['warnings']), $neuro ? implode(' / ', $neuro['warnings']) : 'missing');
$key_assets = $by_id($sugg, 6873);
$check('a company that is one record is suggested as one record', $key_assets && $key_assets['kind'] === 'single');
foreach (array(16 => 'Newport Healthcare', 6 => 'Acadia HealthCare', 22 => 'Teen Challenge', 18 => 'Rite of Passage (twice)', 832 => 'Rite of Passage (twice)') as $oid => $n) {
    $check("$n is not suggested", !$by_id($sugg, $oid));
}
$check('a dismissed company is not suggested', !$by_id(kop_phc_suggest(...array_merge(kop_phc_inputs(), array(array(), array('company:5748' => 1)))), 5748));

// The screen's tab: one card per company, a home-name box per home, the convert and keep buttons.
if (!function_exists('wp_nonce_field')) { function wp_nonce_field() {} }
$hidden = function ($extra = array()) { foreach ($extra as $k => $v) echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">'; };
ob_start();
kop_phc_page_suggested($sugg, '', $hidden);
$screen = ob_get_clean();
file_put_contents($out_dir . '/screen.html', $screen);
$check('the screen has a card per company', substr_count($screen, 'class="kop-ph-card"') === count($sugg));
$check('each home has a name box', substr_count($screen, 'name="home_name[') === array_sum(array_map(function ($s) { return $s['kind'] === 'homes' ? count($s['homes']) : 0; }, $sugg)));
$check('one-record companies fold, the rest make a program', substr_count($screen, 'Fold the company into this record') === count(array_filter($sugg, function ($s) { return $s['kind'] === 'single'; }))
    && substr_count($screen, 'Make it one program') === count(array_filter($sugg, function ($s) { return $s['kind'] === 'homes'; })));
ob_start();
kop_phc_page_suggested($sugg, 'Lamar House', $hidden);
$check('the search finds a company by a home name', substr_count(ob_get_clean(), 'class="kop-ph-card"') === 1);

// ---------------------------------------------------------------------------
echo "\n-- Convert Dimondale --\n";
$before = $snapshot();
$index = kop_operator_pages_index(true);
$dim_slug = (string) ($index['ids'][5748]['slug'] ?? '');
$dim_row = $pdo->query('SELECT * FROM main.wpdl_kop_operators WHERE id = 5748')->fetch(PDO::FETCH_ASSOC);
$pid = kop_phc_convert(5748, 'Dimondale Adolescent Care Facility', array(100030 => 'Long Beach home'), $opts);
$check('converting returns a new program record', $pid > 100000 && !in_array($pid, array_column($dim['homes'], 'id'), true), "#$pid");
$prow = $wpdb->get_row("SELECT name, state, status FROM facilities_v2 WHERE id = $pid", ARRAY_A);
$check('the program record is in California, named for the program', $prow && $prow['state'] === 'CA' && $prow['name'] === 'Dimondale Adolescent Care Facility', json_encode($prow));
$check('its five homes are grouped under it', count(kop_program_homes_homes_of($pid)) === 5);
$check('a home name typed on the screen is kept', (kop_program_homes_program_of(100030)[1] ?? '') === 'Long Beach home');
$check('the company record is gone', !(int) $wpdb->get_var('SELECT COUNT(*) FROM wpdl_kop_operators WHERE id = 5748'));
$check('no record is filed under the company any more', !(int) $wpdb->get_var('SELECT COUNT(*) FROM wpdl_kop_operator_facilities WHERE operator_id = 5748'));
$check('the company is off the /operator/ index', !isset(kop_operator_pages_index(true)['ids'][5748]));
$check('the company is no longer suggested', !$by_id(kop_phc_suggestions(), 5748));
$check('the program is not suggested as a group of homes either', !array_filter(kop_program_homes_suggestions(true), function ($s) { return stripos($s['program_name'], 'Dimondale') === 0; }));
kop_facility_pages_index(true);
$url = kop_facility_page_url($pid);
$check('/operator/' . $dim_slug . '/ goes to the program page', $dim_slug !== '' && $url !== '' && kop_phc_redirect_url($dim_slug) === $url, $url);
$check('/operator/5748/ goes there too', kop_phc_redirect_url('5748') === $url);
try {
    $GLOBALS['kop_test_query_vars']['kop_operator'] = $dim_slug;
    kop_operator_pages_route();
    $check('the /operator/ route redirects', false, 'no redirect');
} catch (KOP_Test_Redirect $r) {
    $check('the /operator/ route redirects with a 301', $r->status === 301 && $r->url === $url, $r->status . ' ' . $r->url);
}
$GLOBALS['kop_test_query_vars'] = array();

$ph = kop_phc_program_history($pid);
$check('the program knows the company it was', $ph && $ph['operator_id'] === 5748 && $ph['history'] === null, 'a draft is hidden from readers');
$data = kop_facility_page_data($pid);
$check('the page data carries it', !empty($data['program_history']));
$html = $render($data);
$check('readers see no History section while it is a draft', strpos($html, 'kop-op-history') === false);
// An admin sees the draft (kop_operator_history_written with drafts allowed).
$data['program_history']['history'] = kop_operator_history_written(kop_phc_for_program($pid)['op'], true);
$html = $render($data);
file_put_contents($out_dir . '/dimondale-program.html', $html);
$check('an admin sees the History section with the draft', strpos($html, 'id="history"') !== false && strpos($html, 'Fleming &amp; Barnes') !== false && strpos($html, '<strong>Draft.</strong>') !== false);
$check('History comes before the Homes', strpos($html, 'id="history"') < strpos($html, 'kop-fp-homes-section'));
$check('the jump links name it', strpos($html, '<a href="#history">History</a>') !== false);
// Once published, everyone sees it.
$pub = kop_phc_for_program($pid);
$pub['op']['historyStatus'] = 'published';
$check('a published history shows to readers', ($h = kop_operator_history_written($pub['op'], false)) && $h['status'] === 'published');
if (function_exists('kop_mobile_facility_payload')) {
    $m = kop_mobile_facility_payload(array('id' => $pid) + $data);
    $check('the app gets no draft', $m['history'] === null);
}

// A history edited on the program page goes back with the company.
$row = kop_phc_rows(true)[5748]['row'];
$json = json_decode($row['json_data'], true);
$json['operator']['historyStatus'] = 'published';
$row['json_data'] = json_encode($json);
$wpdb->update('wpdl_kop_program_conversions', array('operator_json' => json_encode($row), 'updated_at' => gmdate('Y-m-d H:i:s')), array('operator_id' => 5748));

echo "\n-- Undo Dimondale --\n";
$msg = kop_phc_undo(5748, $opts);
echo "  $msg\n";
$back = $pdo->query('SELECT * FROM main.wpdl_kop_operators WHERE id = 5748')->fetch(PDO::FETCH_ASSOC);
$check('the company record is back', $back && $back['name'] === $dim_row['name'] && $back['unique_name'] === $dim_row['unique_name']);
$check('with the history as edited since', $back && (json_decode($back['json_data'], true)['operator']['historyStatus'] ?? '') === 'published');
$check('its records are filed under it again', (int) $wpdb->get_var('SELECT COUNT(*) FROM wpdl_kop_operator_facilities WHERE operator_id = 5748') === 5);
$check('the program record this made is deleted', !(int) $wpdb->get_var("SELECT COUNT(*) FROM facilities_v2 WHERE id = $pid"));
$check('the homes are untied', !kop_program_homes_program_of(100026));
$check('the old address works again', kop_phc_redirect_url($dim_slug) === '' && isset(kop_operator_pages_index(true)['ids'][5748]));
$pdo->prepare('UPDATE main.wpdl_kop_operators SET json_data = ? WHERE id = 5748')->execute(array($dim_row['json_data']));
$check('everything else is as before', $snapshot() === $before, implode(', ', array_keys(array_diff_assoc($snapshot(), $before))));

// ---------------------------------------------------------------------------
echo "\n-- One record --\n";
$before = $snapshot();
$fid = $key_assets['homes'][0]['id'];
$pid = kop_phc_convert(6873, '', array(), $opts);
$check('the one record is the program', $pid === $fid, "#$pid");
$check('no group is made for it', !kop_program_homes_homes_of($pid));
$check('the company is gone and its page goes to the record', !(int) $wpdb->get_var('SELECT COUNT(*) FROM wpdl_kop_operators WHERE id = 6873') && kop_phc_redirect_url('6873') === kop_facility_page_url($fid));
echo '  ' . kop_phc_undo(6873, $opts) . "\n";
$check('undo puts it back exactly', $snapshot() === $before, implode(', ', array_keys(array_diff_assoc($snapshot(), $before))));

// ---------------------------------------------------------------------------
echo "\n-- Every suggestion, then Undo --\n";
$before = $snapshot();
$done = array();
foreach ($sugg as $s) {
    try {
        $done[$s['operator_id']] = kop_phc_convert($s['operator_id'], '', array(), $opts);
    } catch (Throwable $e) {
        $check('convert ' . $s['name'], false, $e->getMessage());
    }
}
$check('every suggestion converts', count($done) === count($sugg), count($done) . ' of ' . count($sugg));
$check('nothing is left to suggest', !kop_phc_suggestions(), implode(', ', array_column(kop_phc_suggestions(), 'name')));
kop_facility_pages_index(true);
foreach ($done as $oid => $pid) {
    $data = kop_facility_page_data($pid);
    if (!$data) continue;
    $html = $render($data);
    if ($oid === 15) file_put_contents($out_dir . '/neurorestorative-program.html', $html);
}
// The stubs run no filters: a program without a page of its own must be one the convertedCompany signal qualifies.
$no_page = array_filter($done, function ($pid) { return kop_facility_page_url($pid) === ''; });
$check('every program has a page (or gets one from the convertedCompany signal)', !array_filter($no_page, function ($pid) {
    return !in_array('convertedCompany', kop_phc_page_signals(array(), array(), $pid), true);
}), count($no_page) . ' qualify only by the signal: #' . implode(', #', $no_page));
foreach (array_reverse($done, true) as $oid => $pid) {
    try { kop_phc_undo($oid, $opts); } catch (Throwable $e) { $check("undo #$oid", false, $e->getMessage()); }
}
$after = $snapshot();
$check('undoing them all leaves every table as it was', $after === $before, implode(', ', array_keys(array_diff_assoc($after, $before))));

register_shutdown_function(function () use ($db_path) {
    try { @unlink($db_path); } catch (Throwable $e) {}
});
echo "  rendered to $out_dir\n";
echo $failures ? "\n$failures failure(s).\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
