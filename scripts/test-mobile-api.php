<?php
/**
 * Offline check of the mobile app's routes (inc/mobile-api.php): the facility
 * and company payloads and the news feed, against a SQLite mirror of
 * production (scripts/sync-prod-sqlite.py writes tmp/prod.sqlite) or, with
 * --fixture, a made-up database built here (facilities, companies, articles
 * whose private columns hold the text PRIVATE-... so a leak is caught by a
 * string search). WordPress is stubbed by scripts/kop-test-harness.php;
 * nothing is written to the mirror.
 *
 * Usage:
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-mobile-api.php [--db=tmp/prod.sqlite] [--id=14182,10371] [--dump=<dir>]
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-mobile-api.php --fixture [--dump=<dir>]
 *
 * --dump writes facility-<id>.json, operator-<id>.json and news.json, the
 * fixtures the app's tests read. Exit code 1 on any FAIL.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'id::', 'dump::', 'fixture', 'list'));
$fixture = isset($args['fixture']);
$list = isset($args['list']);
$want_ids = isset($args['id']) ? array_map('intval', explode(',', (string) $args['id'])) : array();
$dump_dir = isset($args['dump']) ? (string) $args['dump'] : '';

if ($fixture) {
    $db_path = sys_get_temp_dir() . '/kop-mobile-api-fixture-' . getmypid() . '.sqlite';
    register_shutdown_function(function () use ($db_path) { @unlink($db_path); });
    kop_mobile_test_build_fixture($db_path);
} else {
    $db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
    if (!file_exists($db_path)) {
        fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py, or pass --fixture).\n");
        exit(2);
    }
}

// The Indian boarding school exclusion reads through kop_seed_pdo().
function kop_seed_pdo() { return $GLOBALS['pdo']; }

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/operator-pages.php';
require_once dirname(__DIR__) . '/inc/operator-history.php';
require_once dirname(__DIR__) . '/inc/indigenous-schools.php';
require_once dirname(__DIR__) . '/inc/mobile-api.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// ---------------------------------------------------------------------------
// Fixture database
// ---------------------------------------------------------------------------

function kop_mobile_test_build_fixture($path) {
    @unlink($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE facilities_v2 (id INTEGER PRIMARY KEY, unique_name TEXT, json_data TEXT, created_at TEXT, updated_at TEXT,
        schema_version INTEGER, name TEXT, name_key TEXT, state TEXT, city TEXT, country TEXT, status TEXT, facility_type TEXT, start_year INTEGER, end_year INTEGER)');
    $pdo->exec('CREATE TABLE wpdl_kop_facility_locations (facility_id INTEGER, location_key TEXT, role TEXT, source TEXT, needs_review INTEGER, review_reason TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE wpdl_kop_operators (id INTEGER PRIMARY KEY, unique_name TEXT, name TEXT, json_data TEXT, document_folder_id INTEGER, legacy_master_id INTEGER, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE wpdl_kop_operator_facilities (operator_id INTEGER, facility_id INTEGER, relationship TEXT, sort_order INTEGER)');
    $pdo->exec('CREATE TABLE wpdl_kop_operator_links (operator_id INTEGER, link_kind TEXT, link_id INTEGER, link_type TEXT, created_by TEXT, created_at TEXT)');
    $pdo->exec('CREATE TABLE wpdl_posts (ID INTEGER PRIMARY KEY, post_title TEXT, post_excerpt TEXT, post_name TEXT, post_content TEXT, post_modified TEXT, post_modified_gmt TEXT, post_date TEXT, post_status TEXT, post_type TEXT)');
    $pdo->exec('CREATE TABLE wpdl_postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)');
    $pdo->exec('CREATE TABLE news_submissions (id INTEGER PRIMARY KEY, article_title TEXT, alternate_title TEXT, author TEXT, publication_name TEXT, publication_date TEXT,
        article_url TEXT, article_type TEXT, article_location TEXT, tags TEXT, facilities_mentioned TEXT, staff_mentioned TEXT, survivors_mentioned TEXT, content_warnings TEXT, summary TEXT,
        json_data TEXT, generated_output TEXT, story_group_id INTEGER, story_arc_id INTEGER, status TEXT, submitted_by TEXT, submission_notes TEXT, reviewer_notes TEXT, reviewed_by TEXT,
        reviewed_at TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE news_facility_links (news_id INTEGER, facility_id INTEGER, link_type TEXT, created_at TEXT, created_by TEXT)');
    $pdo->exec('CREATE TABLE news_story_arcs (id INTEGER PRIMARY KEY, title TEXT, slug TEXT, description TEXT, match_terms TEXT, facility_label TEXT, facility_url TEXT, status TEXT, display_order INTEGER, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE indigenous_schools (id INTEGER PRIMARY KEY, name TEXT, name_key TEXT)');
    $pdo->exec('CREATE TABLE indigenous_school_news (school_id INTEGER, news_id INTEGER)');

    // Documents through the real normalizer, so the fixture holds the shape the site stores.
    require_once dirname(__DIR__) . '/inc/facility-store.php';
    $doc = function (array $raw) {
        $raw['schema_version'] = 3;   // the v2 path reads the structured location; the legacy path expects address strings
        return kop_facility_json_encode(kop_facility_normalize($raw));
    };
    $facilities = array(
        101 => array(
            'identification' => array('name' => 'Hyde School', 'pastNames' => array('Hyde Leadership School'), 'currentOperator' => 'Hyde Schools Inc'),
            'location' => array('street' => '150 Route 169', 'city' => 'Woodstock', 'state' => 'CT', 'zip' => '06281', 'country' => 'United States'),
            'operatingPeriod' => array('status' => 'Open', 'startYear' => 1966),
            'facilityDetails' => array('type' => 'Therapeutic boarding school', 'capacity' => 200),
            'staff' => array('administrator' => 'Jane Doe', 'notableStaff' => array(array('name' => 'John Roe', 'role' => 'Dean of students'))),
            'criticalIncidents' => array(array('date' => '2019-03-04', 'description' => 'A student was restrained for two hours.', 'source' => 'https://example.org/report')),
            'notes' => 'Known for its character education programme.',
            'profileLinks' => array('https://www.hyde.edu/'),
        ),
        102 => array(
            'identification' => array('name' => 'Copper Canyon Academy', 'currentName' => 'Sedona Sky Academy', 'currentOperator' => 'Universal Health Services'),
            'location' => array('city' => 'Rimrock', 'state' => 'AZ', 'country' => 'United States'),
            'operatingPeriod' => array('status' => 'Closed', 'startYear' => 2003, 'endYear' => 2017),
            'notes' => array('PRIVATE-NOTE must not appear: notes are public on the page, so this sentinel is placed in a private column instead.'),
        ),
        103 => array(
            'identification' => array('name' => 'Hyde School', 'currentOperator' => 'Hyde Schools Inc'),
            'location' => array('city' => 'Bath', 'state' => 'ME', 'country' => 'United States'),
            'operatingPeriod' => array('status' => 'Open', 'startYear' => 1966),
        ),
    );
    // notes on 102 are public on the page; keep the sentinel out of them.
    $facilities[102]['notes'] = array('Renamed Sedona Sky Academy in 2017.');
    $ins = $pdo->prepare('INSERT INTO facilities_v2 (id, unique_name, json_data, created_at, updated_at, schema_version, name, name_key, state, city, country, status, facility_type, start_year, end_year)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($facilities as $id => $raw) {
        $json = $doc($raw);
        $d = json_decode($json, true);
        $ins->execute(array($id, strtolower(str_replace(' ', '-', $d['identification']['name'])) . '-' . $id, $json, '2025-01-01 00:00:00', '2026-09-0' . ($id - 100) . ' 12:00:00',
            $d['schema_version'] ?? 3, $d['identification']['name'], $d['identification']['nameKey'], $d['location']['state'], $d['location']['city'], $d['location']['country'],
            $d['operatingPeriod']['status'], $d['facilityDetails']['type'] ?? '', $d['operatingPeriod']['startYear'], $d['operatingPeriod']['endYear']));
        $pdo->prepare('INSERT INTO wpdl_kop_facility_locations VALUES (?,?,?,?,0,NULL,NULL)')->execute(array($id, $d['location']['state'], 'current', 'address'));
    }

    $op = function (array $o) { return json_encode(array('operator' => $o)); };
    $ops = $pdo->prepare('INSERT INTO wpdl_kop_operators (id, unique_name, name, json_data) VALUES (?,?,?,?)');
    $ops->execute(array(1, 'hyde-schools-inc', 'Hyde Schools Inc', $op(array('name' => 'Hyde Schools Inc', 'headquarters' => 'Bath, ME', 'founded' => '1966', 'status' => 'Active',
        'otherNames' => array('Hyde Schools'), 'websites' => array('https://www.hyde.edu/'),
        'history' => array('Hyde was founded in 1966 by Joseph Gauld in [Bath, Maine](https://example.org/bath).', 'A second campus opened in Woodstock, Connecticut in 1996.'),
        'historyStatus' => 'published', 'historySources' => array(array('label' => 'Company history', 'url' => 'https://example.org/history'))))));
    $ops->execute(array(2, 'universal-health-services', 'Universal Health Services', $op(array('name' => 'Universal Health Services', 'headquarters' => 'King of Prussia, PA', 'status' => 'Active',
        'otherNames' => array('UHS'), 'history' => array('PRIVATE-DRAFT paragraph that an anonymous reader must never see.'), 'historyStatus' => 'draft'))));
    $ops->execute(array(3, 'hyde-schools-inc-2', 'Hyde Schools, Inc.', $op(array('name' => 'Hyde Schools, Inc.'))));
    $of = $pdo->prepare('INSERT INTO wpdl_kop_operator_facilities VALUES (?,?,?,?)');
    $of->execute(array(1, 101, 'current', 0));
    $of->execute(array(1, 103, 'current', 1));
    $of->execute(array(2, 102, 'past', 0));

    $pdo->exec("INSERT INTO news_story_arcs (id, title, slug, description, status, display_order) VALUES
        (1, 'Hyde School abuse lawsuit', 'hyde-lawsuit', 'Former students sue the school.', 'active', 1),
        (2, 'An archived story', 'archived-story', '', 'archived', 2)");
    $news = $pdo->prepare('INSERT INTO news_submissions (id, article_title, alternate_title, author, publication_name, publication_date, article_url, article_type, summary, content_warnings,
        story_group_id, story_arc_id, status, submitted_by, submission_notes, reviewer_notes, reviewed_by, staff_mentioned, survivors_mentioned, json_data, generated_output, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $priv = array('PRIVATE-SUBMITTER', 'PRIVATE-SUBMISSION-NOTE', 'PRIVATE-REVIEWER-NOTE', 'PRIVATE-REVIEWER', 'PRIVATE-STAFF', 'PRIVATE-SURVIVOR', '{"PRIVATE-JSON":1}', 'PRIVATE-GENERATED');
    $rows = array(
        array(1, 'Hyde School sued by former students', '', 'A Reporter', 'Portland Press Herald', '2026-02-10', 'https://example.org/news/1', 'lawsuit', 'Three former students filed suit.', '["abuse"]', null, 1, 'published'),
        array(2, 'School responds to lawsuit', 'Hyde School answers the lawsuit', 'A Reporter', 'Bangor Daily News', '2026-02-12', 'https://example.org/news/2', 'lawsuit', '', '[]', 1, 1, 'approved'),
        array(3, 'Arizona academy closes', '', '', 'Arizona Republic', '2025-01-20', 'https://example.org/news/3', 'closure', 'The academy closed in January.', '[]', null, null, 'approved'),
        array(4, 'State audits boarding schools', '', '', 'Hartford Courant', '2025-01-05', 'https://example.org/news/4', 'general', '', '[]', null, null, 'published'),
        array(5, 'An older feature', '', '', 'The Atlantic', '2024-11-01', 'https://example.org/news/5', 'expose', '', '[]', null, null, 'approved'),
        array(6, 'Residential school graves found', '', '', 'CBC', '2026-03-01', 'https://example.org/news/6', 'general', '', '[]', null, null, 'approved'),
        array(7, 'PRIVATE-REJECTED article', '', '', 'Nowhere', '2026-03-02', 'https://example.org/news/7', 'general', '', '[]', null, null, 'rejected'),
        array(8, 'PRIVATE-DRAFT article', '', '', 'Nowhere', '2026-03-03', 'https://example.org/news/8', 'general', '', '[]', null, null, 'draft'),
    );
    foreach ($rows as $r) {
        $news->execute(array_merge($r, array($priv[0], $priv[1], $priv[2], $priv[3], $priv[4], $priv[5], $priv[6], $priv[7], '2026-01-01 00:00:00', '2026-01-02 00:00:00')));
    }
    $pdo->exec("INSERT INTO news_facility_links VALUES (1, 101, 'primary', NULL, NULL), (1, 103, 'mentioned', NULL, NULL), (2, 103, 'primary', NULL, NULL), (3, 102, 'primary', NULL, NULL), (7, 101, 'primary', NULL, NULL)");
    $pdo->exec("INSERT INTO indigenous_schools VALUES (1, 'Kamloops Indian Residential School', 'kamloops indian residential school')");
    $pdo->exec("INSERT INTO indigenous_school_news VALUES (1, 6)");
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

$private_keys = array('author', 'submitted_by', 'submitter_ip', 'submission_notes', 'reviewer_notes', 'reviewed_by', 'reviewed_at',
    'json_data', 'generated_output', 'staff_mentioned', 'survivors_mentioned', 'facilities_mentioned', 'html', 'survivorTestimony', 'email', 'phone');

/** Every key and string under $v: private keys, HTML and the fixture's sentinel text. Returns a list of problems. */
$walk = function ($v, $path = '') use (&$walk, $private_keys) {
    $problems = array();
    if (is_array($v)) {
        foreach ($v as $k => $child) {
            if (is_string($k) && in_array($k, $private_keys, true) && "$path.$k" !== '.inspections.summary.phone') $problems[] = "private key $path.$k";   // the page prints the licensed phone as "Phone on file"
            $problems = array_merge($problems, $walk($child, $path . '.' . $k));
        }
    } elseif (is_string($v)) {
        if (preg_match('/<[a-z!\/][^>]*>/i', $v)) $problems[] = "HTML at $path: " . substr($v, 0, 60);
        if (strpos($v, 'PRIVATE-') !== false) $problems[] = "sentinel at $path: " . substr($v, 0, 60);
    }
    return $problems;
};
$urls = function ($v) use (&$urls) {
    $out = array();
    if (is_array($v)) {
        foreach ($v as $k => $child) {
            if ($k === 'url' && is_string($child) && $child !== '') $out[] = $child;
            $out = array_merge($out, $urls($child));
        }
    }
    return $out;
};
$bad_urls = function (array $list) {
    return array_values(array_filter($list, function ($u) { return !preg_match('#^(https?://|/)#i', $u); }));
};
$dump = function ($name, $data) use ($dump_dir) {
    if ($dump_dir === '') return;
    if (!is_dir($dump_dir)) mkdir($dump_dir, 0777, true);
    file_put_contents(rtrim($dump_dir, '/\\') . '/' . $name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
};
/** A stand-in for WP_REST_Request with one header. */
class KOP_Mobile_Test_Request {
    private $headers; private $params;
    public function __construct(array $headers = array(), array $params = array()) { $this->headers = $headers; $this->params = $params; }
    public function get_header($name) { return $this->headers[$name] ?? null; }
    public function get_param($name) { return $this->params[$name] ?? null; }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data; public $status; public $headers = array();
        public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
        public function header($k, $v) { $this->headers[$k] = $v; }
        public function get_data() { return $this->data; }
    }
}

// ---------------------------------------------------------------------------
// Facilities
// ---------------------------------------------------------------------------

echo "-- Facilities --\n";
$index = kop_facility_pages_index(true);
$check('facility index has entries', count($index['ids']) > 0, count($index['ids']) . ' pages');
if ($list) {
    foreach (array_slice($index['ids'], 0, 20, true) as $id => $e) echo "  $id  {$e['slug']}  {$e['name']}\n";
}

if ($fixture) {
    $check('slug hyde-school-ct -> 101', kop_mobile_resolve_facility_id('hyde-school-ct') === 101);
    $check('slug hyde-school-me -> 103', kop_mobile_resolve_facility_id('hyde-school-me') === 103);
    $check('slug copper-canyon-academy-az -> 102', kop_mobile_resolve_facility_id('copper-canyon-academy-az') === 102);
    $check('numeric ref resolves', kop_mobile_resolve_facility_id('102') === 102);
    $check('unknown slug -> 0', kop_mobile_resolve_facility_id('no-such-place-xx') === 0);
    $check('unknown id -> 0', kop_mobile_resolve_facility_id('999999') === 0);
    $check('empty ref -> 0', kop_mobile_resolve_facility_id('') === 0);
    $check('unknown facility -> null', kop_mobile_facility('no-such-place-xx') === null);
    $sample_ids = array(101, 102, 103);
} else {
    $all = array_keys($index['ids']);
    $sample_ids = $want_ids ?: array_slice($all, 0, 3);
    $first = $all[0];
    $slug = $index['ids'][$first]['slug'];
    $check("slug $slug round-trips", kop_mobile_resolve_facility_id($slug) === (int) $index['slugs'][$slug]);
    $check('unknown slug -> 0', kop_mobile_resolve_facility_id('no-such-place-xx-' . time()) === 0);
}

$required = array('api_version', 'id', 'slug', 'url', 'name', 'status', 'place', 'operator', 'siblings', 'addresses', 'operated', 'summary',
    'incidents', 'staff', 'news', 'lawsuits', 'memorials', 'inspections', 'documents', 'updated_at', 'formerly', 'aka', 'profile_links', 'resource_links');
foreach ($sample_ids as $fid) {
    $t = microtime(true);
    $payload = kop_mobile_facility($fid);
    $ms = (int) ((microtime(true) - $t) * 1000);
    $check("facility $fid builds", is_array($payload), $ms . ' ms');
    if (!is_array($payload)) continue;
    $missing = array_values(array_diff($required, array_keys($payload)));
    $check("facility $fid has the app's keys", !$missing, implode(',', $missing));
    $problems = $walk($payload);
    $check("facility $fid has no private key, HTML or sentinel", !$problems, implode('; ', array_slice($problems, 0, 3)));
    $bad = $bad_urls($urls($payload));
    $check("facility $fid urls are absolute or site paths", !$bad, implode(' ', array_slice($bad, 0, 3)));
    $json = json_encode($payload);
    $check("facility $fid encodes to JSON under 500 KB", $json !== false && strlen($json) < 500 * 1024, (int) (strlen((string) $json) / 1024) . ' KB');
    $check("facility $fid documents is {folder_id, url}", array_keys($payload['documents']) === array('folder_id', 'url'));
    $check("facility $fid id and slug match the index", $payload['id'] === $fid && $payload['slug'] === $index['ids'][$fid]['slug']);
    $check("facility $fid url is the page url", $payload['url'] === kop_facility_page_url($fid));
    if ($payload['inspections'] !== null) {
        $check("facility $fid inspections cut to " . KOP_MOBILE_API_MAX_REPORTS, count($payload['inspections']['reports']) <= KOP_MOBILE_API_MAX_REPORTS && isset($payload['inspections']['more']));
    }
    foreach ($payload['news'] as $i => $card) {
        $check("facility $fid news card $i has title/outlet/url", isset($card['title'], $card['outlet'], $card['url'], $card['date']));
        break;
    }
    $dump("facility-$fid.json", $payload);
}

if ($fixture) {
    $p = kop_mobile_facility('hyde-school-ct');
    $check('101 formerly lists Hyde Leadership School', in_array('Hyde Leadership School', (array) $p['formerly'], true), json_encode($p['formerly']));
    $check('101 operator name and url', $p['operator']['name'] === 'Hyde Schools Inc' && strpos($p['operator']['url'], '/hyde-schools-inc/') !== false, json_encode($p['operator']));
    $check('101 sibling is the Maine school', count($p['siblings']) === 1 && $p['siblings'][0]['name'] === 'Hyde School' && $p['siblings'][0]['place'] === 'Bath, Maine', json_encode($p['siblings']));
    $check('101 news: the lawsuit article only (rejected one dropped)', count($p['news']) === 1 && $p['news'][0]['id'] === 1 && $p['news'][0]['outlet'] === 'Portland Press Herald', json_encode(wp_list_pluck($p['news'], 'id')));
    $check('101 operated text', $p['operated'] === '1966 to present', $p['operated']);
    $check('101 incident kept', count($p['incidents']) === 1, json_encode($p['incidents']));
    $check('101 staff kept', count($p['staff']) >= 1, json_encode($p['staff']));
    $c = kop_mobile_facility(102);
    $check('102 current name Sedona Sky Academy', $c['current_name'] === 'Sedona Sky Academy', $c['current_name']);
    $check('102 operated "2003 to 2017"', $c['operated'] === '2003 to 2017', $c['operated']);
}

// ---------------------------------------------------------------------------
// Operators
// ---------------------------------------------------------------------------

echo "-- Operators --\n";
$oindex = kop_operator_pages_index(true);
$check('operator index has entries', count($oindex['ids']) > 0, count($oindex['ids']) . ' companies');
$orequired = array('api_version', 'id', 'name', 'url', 'aka', 'status', 'facts', 'facilities', 'program_tree', 'open_count', 'news', 'lawsuits', 'memorials', 'timeline', 'people', 'history', 'summary', 'documents', 'websites');

if ($fixture) {
    $check('operator slug hyde-schools-inc -> 1', kop_mobile_resolve_operator_id('hyde-schools-inc') === 1);
    $check('operator id 1', kop_mobile_resolve_operator_id('1') === 1);
    $check('duplicate id 3 -> canonical 1', kop_mobile_resolve_operator_id('3') === 1, json_encode($oindex['alias_of']));
    $check('name "Hyde Schools, Inc." -> 1', kop_mobile_resolve_operator_id('Hyde Schools, Inc.', true) === 1);
    $check('name "UHS" -> 2', kop_mobile_resolve_operator_id('UHS', true) === 2);
    $check('name "Universal Health Services Inc" -> 2', kop_mobile_resolve_operator_id('Universal Health Services Inc', true) === 2);
    $check('unknown operator -> 0', kop_mobile_resolve_operator_id('nobody-at-all') === 0 && kop_mobile_resolve_operator_id('Nobody At All', true) === 0);
    $sample_ops = array(1, 2);
} else {
    $sample_ops = array_slice(array_keys($oindex['ids']), 0, 2);
    $oslug = $oindex['ids'][$sample_ops[0]]['slug'];
    $check("operator slug $oslug round-trips", kop_mobile_resolve_operator_id($oslug) === $sample_ops[0]);
    if ($oindex['alias_of']) {
        $dup = array_key_first($oindex['alias_of']);
        $check("duplicate operator $dup -> canonical", kop_mobile_resolve_operator_id((string) $dup) === (int) $oindex['alias_of'][$dup]);
    }
}
foreach ($sample_ops as $oid) {
    $t = microtime(true);
    $payload = kop_mobile_operator($oid);
    $ms = (int) ((microtime(true) - $t) * 1000);
    $check("operator $oid builds", is_array($payload), $ms . ' ms');
    if (!is_array($payload)) continue;
    $missing = array_values(array_diff($orequired, array_keys($payload)));
    $check("operator $oid has the app's keys", !$missing, implode(',', $missing));
    $problems = $walk($payload);
    $check("operator $oid has no private key, HTML or sentinel", !$problems, implode('; ', array_slice($problems, 0, 3)));
    $bad = $bad_urls($urls($payload));
    $check("operator $oid urls are absolute or site paths", !$bad, implode(' ', array_slice($bad, 0, 3)));
    $check("operator $oid history is null or published", $payload['history'] === null || $payload['history']['status'] === 'published', json_encode($payload['history']['status'] ?? null));
    $check("operator $oid documents is {folder_id, program_total, url}", array_keys($payload['documents']) === array('folder_id', 'program_total', 'url'));
    $dump("operator-$oid.json", $payload);
}
if ($fixture) {
    $h = kop_mobile_operator(1);
    $check('Hyde history published with 2 paragraphs and a source', $h['history'] && count($h['history']['paragraphs']) === 2 && count($h['history']['sources']) === 1, json_encode($h['history']));
    $check('Hyde history keeps the [words](url) link for the app', strpos($h['history']['paragraphs'][0], '[Bath, Maine](https://example.org/bath)') !== false);
    $check('Hyde lists both schools', count($h['facilities']) === 2, json_encode(wp_list_pluck($h['facilities'], 'name')));
    $check('Hyde aka', $h['aka'] === array('Hyde Schools'), json_encode($h['aka']));
    $u = kop_mobile_operator(2);
    $check('UHS draft history hidden', $u['history'] === null);
    $check('UHS news lists the closure article through its program', count($u['news']) === 1 && $u['news'][0]['id'] === 3, json_encode($u['news']));
}

// ---------------------------------------------------------------------------
// News
// ---------------------------------------------------------------------------

echo "-- News --\n";
$feed = kop_mobile_news_feed(array('per_page' => 50));
$check('news feed builds', is_array($feed) && isset($feed['items'], $feed['total'], $feed['months'], $feed['arcs']));
$check('news feed has items', $feed['total'] > 0 && count($feed['items']) > 0, $feed['total'] . ' total');
$problems = $walk($feed);
$check('news feed has no private key, HTML or sentinel', !$problems, implode('; ', array_slice($problems, 0, 3)));
$bad = $bad_urls($urls($feed));
$check('news urls are absolute or site paths', !$bad, implode(' ', array_slice($bad, 0, 3)));
$item_keys = array('id', 'title', 'outlet', 'date', 'date_label', 'url', 'type', 'summary', 'content_warnings', 'image', 'facilities', 'story_arc', 'story_group_id');
$check('news item keys', $feed['items'] && array_keys($feed['items'][0]) === $item_keys, implode(',', array_keys($feed['items'][0] ?? array())));
$dates = array_map(function ($i) { return $i['date']; }, $feed['items']);
$sorted = $dates; rsort($sorted);
$check('news newest first', $dates === $sorted);
$months = wp_list_pluck($feed['months'], 'month');
$sorted = $months; rsort($sorted);
$check('months sorted desc and well-formed', $months === $sorted && !array_filter($months, function ($m) { return !preg_match('/^\d{4}-\d{2}$/', $m); }), implode(',', array_slice($months, 0, 4)));
foreach ($feed['arcs'] as $a) {
    $check('arc ' . $a['slug'] . ' is active with articles', $a['status'] === 'active' && $a['article_count'] > 0);
}
$clamped = kop_mobile_news_feed(array('per_page' => 500, 'page' => 0));
$check('per_page clamps to 50 and page to 1', $clamped['per_page'] === 50 && $clamped['page'] === 1);
$small = kop_mobile_news_feed(array('per_page' => 2, 'page' => 2));
$check('paging: page 2 of 2 continues the list', $feed['total'] < 3 || (count($small['items']) >= 1 && $small['items'][0]['id'] === $feed['items'][2]['id']));
if ($months) {
    $m = $months[0];
    $arch = kop_mobile_news_feed(array('archive' => $m, 'per_page' => 50));
    $off = array_filter($arch['items'], function ($i) use ($m) { return strpos($i['date'], $m) !== 0; });
    $check("archive $m returns only that month", $arch['total'] > 0 && !$off, $arch['total'] . ' items');
}
$check('bad archive value ignored', kop_mobile_news_feed(array('archive' => "2026-01' OR 1=1"))['total'] === $feed['total']);
$check('unknown story -> empty', kop_mobile_news_feed(array('story' => 'no-such-story'))['total'] === 0);
if ($feed['arcs']) {
    $arc = $feed['arcs'][0];
    $st = kop_mobile_news_feed(array('story' => $arc['slug'], 'per_page' => 50));
    $off = array_filter($st['items'], function ($i) use ($arc) { return !$i['story_arc'] || $i['story_arc']['slug'] !== $arc['slug']; });
    $check('story ' . $arc['slug'] . ' returns only its articles', $st['total'] === $arc['article_count'] && !$off && $st['story']['slug'] === $arc['slug'], $st['total'] . ' items');
}
$linked = null;
foreach ($feed['items'] as $i) { if ($i['facilities']) { $linked = $i; break; } }
if ($linked) {
    $f = $linked['facilities'][0];
    $check('facility chip has id, name, slug, url', isset($f['id'], $f['name'], $f['slug'], $f['url']) && $f['id'] > 0 && $f['name'] !== '', json_encode($f));
    $ff = kop_mobile_news_feed(array('facility' => $f['id'], 'per_page' => 50));
    $off = array_filter($ff['items'], function ($i) use ($f) { return !in_array($f['id'], wp_list_pluck($i['facilities'], 'id'), true); });
    $check('facility=' . $f['id'] . ' returns only its articles', $ff['total'] > 0 && !$off, $ff['total'] . ' items');
    $check('facility filter matches the facility payload news list', $ff['total'] === count(kop_mobile_facility($f['id'])['news']));
}
$dump('news.json', kop_mobile_news_feed(array('per_page' => 20)));

if ($fixture) {
    $ids = wp_list_pluck($feed['items'], 'id');
    $check('fixture: the 5 public articles newest first (rejected, draft and the Indian school article left out)', $ids === array(2, 1, 3, 4, 5), json_encode($ids));
    $check('fixture: alternate title wins', $feed['items'][0]['title'] === 'Hyde School answers the lawsuit');
    $check('fixture: content warnings decoded', $feed['items'][1]['content_warnings'] === array('abuse'));
    $check('fixture: arc on the two lawsuit articles', $feed['items'][0]['story_arc']['slug'] === 'hyde-lawsuit' && $feed['items'][1]['story_arc']['slug'] === 'hyde-lawsuit' && $feed['items'][2]['story_arc'] === null);
    $check('fixture: only the active arc listed', count($feed['arcs']) === 1 && $feed['arcs'][0]['slug'] === 'hyde-lawsuit' && $feed['arcs'][0]['article_count'] === 2, json_encode($feed['arcs']));
    $check('fixture: months 2026-02, 2025-01, 2024-11', $months === array('2026-02', '2025-01', '2024-11'), json_encode($feed['months']));
    $check('fixture: article 1 linked to both Hyde schools', wp_list_pluck($feed['items'][1]['facilities'], 'id') === array(101, 103), json_encode($feed['items'][1]['facilities']));
    $check('fixture: chips carry page slugs', $feed['items'][1]['facilities'][0]['slug'] === 'hyde-school-ct' && strpos($feed['items'][1]['facilities'][0]['url'], '/hyde-school-ct/') !== false);
    $check('fixture: archive 2025-01 has 2', kop_mobile_news_feed(array('archive' => '2025-01'))['total'] === 2);
    $check('fixture: facility 101 feed = the one public linked article', wp_list_pluck(kop_mobile_news_feed(array('facility' => 101))['items'], 'id') === array(1));
    $check('fixture: story_group_id kept for cross-outlet coverage', $feed['items'][0]['story_group_id'] === 1 && $feed['items'][1]['story_group_id'] === null);
    $check('fixture: date label', $feed['items'][0]['date_label'] === 'Feb 12, 2026', $feed['items'][0]['date_label']);
}

// ---------------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------------

echo "-- Responses --\n";
$etag = kop_mobile_etag(array('facility', 101, 'fp', '2026'));
$check('etag is a quoted md5', preg_match('/^"[0-9a-f]{32}"$/', $etag) === 1, $etag);
$check('etag is stable', $etag === kop_mobile_etag(array('facility', 101, 'fp', '2026')));
$check('etag changes with the input', $etag !== kop_mobile_etag(array('facility', 101, 'fp', '2027')));
$check('If-None-Match matches', kop_mobile_etag_matches(new KOP_Mobile_Test_Request(array('if_none_match' => $etag)), $etag));
$check('weak If-None-Match matches', kop_mobile_etag_matches(new KOP_Mobile_Test_Request(array('if_none_match' => 'W/' . $etag)), $etag));
$check('If-None-Match list matches', kop_mobile_etag_matches(new KOP_Mobile_Test_Request(array('if_none_match' => '"x", ' . $etag)), $etag));
$check('other etag does not match', !kop_mobile_etag_matches(new KOP_Mobile_Test_Request(array('if_none_match' => '"other"')), $etag));
$check('no header does not match', !kop_mobile_etag_matches(new KOP_Mobile_Test_Request(), $etag));
$res = kop_mobile_response(array('ok' => 1), $etag);
$check('response carries ETag and Cache-Control', $res->status === 200 && $res->headers['ETag'] === $etag && strpos($res->headers['Cache-Control'], 'max-age=600') !== false);
$nm = kop_mobile_not_modified($etag);
$check('304 has no body', $nm->status === 304 && $nm->data === null);
$nf = kop_mobile_not_found('x');
$check('404 is a WP_Error with status 404', is_wp_error($nf) && $nf->data['status'] === 404);

// The REST callbacks end to end, with the stand-in request.
$r = kop_mobile_facility_rest(new KOP_Mobile_Test_Request(array(), array('ref' => $fixture ? 'hyde-school-ct' : $index['ids'][$sample_ids[0]]['slug'])));
$check('facility route returns the payload with an ETag', $r instanceof WP_REST_Response && $r->status === 200 && isset($r->data['name']) && isset($r->headers['ETag']));
$r2 = kop_mobile_facility_rest(new KOP_Mobile_Test_Request(array('if_none_match' => $r->headers['ETag']), array('ref' => $r->data['slug'])));
$check('facility route answers 304 to its own ETag', $r2 instanceof WP_REST_Response && $r2->status === 304);
$check('facility route 404s an unknown slug', is_wp_error(kop_mobile_facility_rest(new KOP_Mobile_Test_Request(array(), array('ref' => 'no-such-place-xx')))));
$r = kop_mobile_operator_rest(new KOP_Mobile_Test_Request(array(), array('ref' => '', 'name' => $fixture ? 'UHS' : $oindex['ids'][$sample_ops[0]]['display'])));
$check('operator route by name', $r instanceof WP_REST_Response && $r->status === 200 && isset($r->data['name']), is_wp_error($r) ? $r->get_error_message() : '');
$r = kop_mobile_news_rest(new KOP_Mobile_Test_Request(array(), array('per_page' => '3')));
$check('news route returns 3 items with an ETag', $r instanceof WP_REST_Response && count($r->data['items']) === min(3, $feed['total']) && isset($r->headers['ETag']));
$r2 = kop_mobile_news_rest(new KOP_Mobile_Test_Request(array('if_none_match' => $r->headers['ETag']), array('per_page' => '3')));
$check('news route answers 304 to its own ETag', $r2->status === 304);
$r3 = kop_mobile_news_rest(new KOP_Mobile_Test_Request(array('if_none_match' => $r->headers['ETag']), array('per_page' => '4')));
$check('news ETag differs per query', $r3->status === 200);

echo "\n" . ($failures ? "$failures FAILED\n" : "All checks passed\n");
exit($failures ? 1 : 0);
